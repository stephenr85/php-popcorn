<?php

namespace Rushing\Popcorn\Runner;

use Closure;
use Rushing\Popcorn\Contracts\SessionRunner;
use Rushing\Popcorn\Runner\Concerns\HandlesRunnerIo;
use Throwable;

/**
 * A live {@see Session} over one `proc_open` child: stdin and stdout are pipes, stderr goes to a
 * private file (so a chatty child can never fill a pipe nobody reads and wedge itself), and the
 * environment is EXACTLY the one the {@see SessionRunner} hands in: there is no host passthrough.
 *
 * The substrate decides the argv (a `bwrap … --` prefix under the bubble Runner, the bare interpreter
 * under a dev degrade) and whether that counts as `sandboxed`; this class only runs it and keeps the
 * lifecycle honest:
 *
 * - **Acquisition is total** (amend, Codex review ff28ff40 finding 2). {@see start()} validates every launch
 *   string first (a NUL byte would make `proc_open` throw), creates the stderr file itself (no `tempnam`
 *   fallback, so a failure can never leave an unknown file behind), and wraps the spawn: ANY throwable or a
 *   partial acquisition (a process without both pipes) releases everything acquired so far (the child is
 *   killed and reaped, every pipe and the file handle are closed, the file is unlinked) and comes back as a
 *   {@see RefusedSession}. Nothing is thrown for a launch outcome.
 * - The child's exit status is captured the FIRST time it is seen (`proc_get_status` reports the exit
 *   code exactly once), so a session that died by itself closes as `NonZeroExit` with its real code.
 * - {@see close()} closes stdin first, gives the child a grace period to leave on its own, then
 *   SIGTERM, then SIGKILL. A child the host had to stop closes as `Success` with the signal recorded:
 *   ending a session is the host's call, not the child's failure.
 * - **Retained stderr is capped per host call, with an explicit marker and lower-bound counts** (PRR03 P4, root
 *   `cb7f7936`; ADR-0003). The child writes through its O_APPEND descriptor. The host reads ONLY through a separate
 *   read-only handle (its own offset, never moved by the child's appends) and NEVER writes into the file:
 *   - every `send()`/`receive()`/`isRunning()` COMPACTS once the file passes {@see STDERR_COMPACT_BYTES}: it keeps
 *     the file's last {@see STDERR_TAIL_BYTES} in host memory, then truncates the file to 0, so the child's later
 *     appends start a new epoch in true order;
 *   - a byte the child (or a descendant sharing its stderr) writes between the compaction's size read and its
 *     truncate is destroyed unseen. That window cannot be observed, so EVERY compaction sets `stderrLossPossible`;
 *   - `close()` returns at most {@see STDERR_TAIL_BYTES} bytes in total, the explicit marker and gap lines included.
 *     The child's bytes appear in the order written, but are not promised contiguous once anything was omitted;
 *   - `stderrBytesObservedAtLeast` and `stderrBytesOmittedAtLeast` are LOWER bounds, never exact totals.
 *   RESIDUAL BOUNDARY: this is a cap per host call, NOT a continuous disk bound. Between two host calls a child can
 *   grow the file on disk without limit, and after a compaction it appends again. Memory stays a small constant multiple of the tail.
 * - `close()` is idempotent and exception-safe: the file is unlinked and the Result cached in a `finally`,
 *   and it also runs from the destructor, so an abandoned session never leaks a process or a file.
 *
 * @api
 */
class ProcessSession implements Session
{
    use HandlesRunnerIo;

    /** How long close() waits after each step (stdin EOF, SIGTERM) before escalating. */
    private const GRACE_MS = 2000;

    private const POLL_US = 10000;

    /** Once the stderr file passes this size, a host call compacts it, keeping its last STDERR_TAIL_BYTES in memory. */
    public const STDERR_COMPACT_BYTES = 1048576; // 1 MiB

    /** Room kept, within STDERR_TAIL_BYTES, for the marker and gap lines close() prepends or inserts. */
    private const STDERR_MARKER_RESERVE = 512;

    private const STDERR_GAP = "[popcorn: possible gap]\n";

    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    /** @var resource|null the O_APPEND handle shared with the child's stderr (the host only truncates through it) */
    private $stderr;

    /** @var resource|null the host's own read-only handle on the stderr file: its offset is never the child's */
    private $stderrRead;

    private bool $exited = false;

    private ?int $exitCode = null;

    private ?int $signal = null;

    private ?Result $result = null;

    /** The last STDERR_TAIL_BYTES of the epochs already truncated away, held in host memory (never written back). */
    private string $stderrHeld = '';

    /** Lower bound: bytes of child stderr the host has observed in truncated epochs. */
    private int $stderrObserved = 0;

    private int $stderrEpochs = 0;

    private bool $stderrLossPossible = false;

    private float $startedAt;

    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     * @param  resource  $stderr
     * @param  resource  $stderrRead
     */
    private function __construct(
        $process,
        array $pipes,
        $stderr,
        $stderrRead,
        private string $stderrFile,
        private bool $sandboxed,
        private Grant $grant,
        private ?int $pid,
        private ?Closure $stderrProbe = null,
    ) {
        $this->process = $process;
        $this->pipes = $pipes;
        $this->stderr = $stderr;
        $this->stderrRead = $stderrRead;
        $this->startedAt = microtime(true);
    }

    /**
     * Launch `$argv` with exactly `$env` and return the live session, or a {@see RefusedSession} when
     * the launch itself could not happen. What a binary that cannot be executed gives depends on how the
     * platform's `proc_open` spawns: where posix_spawn refuses it (observed on macOS with PHP 8.4), the spawn returns
     * false and the launch is a `SubstrateUnavailable` refusal; where a fork succeeds and only the exec fails, the
     * child starts, exits 127, and the session closes as `NonZeroExit`. Either way nothing is thrown or left behind.
     *
     * @param  list<string>  $argv
     * @param  array<string, string>  $env
     * @param  ?string  $stderrDir  where the private stderr file goes (default: the system temp dir)
     * @param  ?Closure  $spawn  @internal failure-injection seam for tests:
     *                           fn(array $argv, array $descriptors, ?string $cwd, array $env): array{0: mixed, 1: array}
     *                           returning [process, pipes]; default is `proc_open`
     * @param  ?Closure  $stderrProbe  @internal interleaving seam for tests: fn(string $point, resource $writeHandle): void,
     *                                 called during a compaction at `after-stat`, `after-seek` and `after-truncate`
     *                                 (and at `after-seek` when close() reads), so a test can write bytes at an exact
     *                                 point instead of racing a real child. Never set in production.
     */
    public static function start(
        array $argv,
        array $env,
        bool $sandboxed,
        Grant $grant,
        ?string $cwd = null,
        ?string $stderrDir = null,
        ?Closure $spawn = null,
        ?Closure $stderrProbe = null,
    ): Session {
        $invalid = self::invalidLaunch($argv, $env, $cwd);

        if ($invalid !== null) {
            return new RefusedSession(Result::substrateUnavailable("popcorn: refused to launch a session: {$invalid}."), $grant);
        }

        $path = null;
        $stderr = null;
        $stderrRead = null;
        $process = null;
        $pipes = [];

        try {
            $dir = rtrim($stderrDir ?? sys_get_temp_dir(), '/');
            $path = $dir.'/popcorn-session-err-'.bin2hex(random_bytes(8));

            // Exclusive create ('x'): the name is ours or nothing is. No tempnam(), whose fallback directory
            // would put an unknown file somewhere a failure could not clean up.
            $created = @fopen($path, 'x');

            if ($created === false) {
                $path = null;

                return new RefusedSession(Result::substrateUnavailable("popcorn: could not create the session stderr file in `{$dir}`."), $grant);
            }

            fclose($created);
            $stderr = @fopen($path, 'a+'); // O_APPEND: the child always writes at the end, even after a compaction

            if ($stderr === false) {
                $stderr = null;

                throw new \RuntimeException('could not reopen the stderr file for append');
            }

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr];
            [$process, $pipes] = ($spawn ?? self::spawn(...))($argv, $descriptors, $cwd, $env);

            if (! is_resource($process)) {
                $process = null;
                self::abandon(null, $pipes, $stderr, $path, $stderrRead);

                return new RefusedSession(Result::substrateUnavailable("popcorn: could not spawn `{$argv[0]}`."), $grant);
            }

            if (! is_resource($pipes[0] ?? null) || ! is_resource($pipes[1] ?? null)) {
                self::abandon($process, $pipes, $stderr, $path, $stderrRead);

                return new RefusedSession(Result::substrateUnavailable("popcorn: `{$argv[0]}` started without both stdio pipes; it was stopped."), $grant);
            }

            // The host's own read handle (PLAN-P4: opened after the spawn): a separate open file description, so the
            // child's O_APPEND writes never move the offset the host reads from. Failing here is a partial acquisition:
            // the catch below stops and reaps the child and releases everything.
            $stderrRead = @fopen($path, 'r');

            if ($stderrRead === false) {
                $stderrRead = null;

                throw new \RuntimeException('could not open the stderr file for reading');
            }

            $status = proc_get_status($process);
            $session = new self($process, $pipes, $stderr, $stderrRead, $path, $sandboxed, $grant, $status['pid'], $stderrProbe);
            $session->observe($status);

            return $session;
        } catch (Throwable $e) {
            self::abandon($process, $pipes, $stderr, $path, $stderrRead);

            return new RefusedSession(Result::substrateUnavailable('popcorn: the session launch failed ('.$e::class.': '.$e->getMessage().').'), $grant);
        }
    }

    /**
     * The default spawn: `proc_open` with exactly the given argv, descriptors, cwd and environment. `$pipes` starts as
     * an empty array because `proc_open` does NOT always assign it: when the spawn itself fails (posix_spawn or fork
     * failing, a missing binary on a posix_spawn platform) it returns false before it initialises the out parameter,
     * which would otherwise stay null (amend, fresh Claude review of PRR03 unit 2). The caller's total acquisition
     * relies on getting an array back either way.
     *
     * @param  list<string>  $argv
     * @param  array<int, array{0: string, 1: string}|resource>  $descriptors
     * @param  array<string, string>  $env
     * @return array{0: resource|false, 1: array<int, resource>}
     */
    private static function spawn(array $argv, array $descriptors, ?string $cwd, array $env): array
    {
        $pipes = [];
        $process = @proc_open($argv, $descriptors, $pipes, $cwd, $env);

        return [$process, $pipes];
    }

    /**
     * Why a launch is refused before anything is acquired, or null. A NUL byte anywhere in argv, env or cwd
     * makes `proc_open` throw a ValueError, so it is caught here as a refusal instead.
     *
     * @param  array<mixed>  $argv
     * @param  array<mixed>  $env
     */
    private static function invalidLaunch(array $argv, array $env, ?string $cwd): ?string
    {
        if ($argv === [] || ! array_is_list($argv)) {
            return 'a session needs a non-empty argv list';
        }

        foreach ($argv as $i => $arg) {
            if (! is_string($arg) || ($i === 0 && $arg === '') || str_contains($arg, "\0")) {
                return "argv[{$i}] is not a launchable string";
            }
        }

        foreach ($env as $name => $value) {
            if (! is_string($name) || $name === '' || str_contains($name, '=') || str_contains($name, "\0") || ! is_string($value) || str_contains($value, "\0")) {
                return 'the environment holds an invalid name or value';
            }
        }

        if ($cwd !== null && ($cwd === '' || str_contains($cwd, "\0"))) {
            return 'the working directory is not a usable path';
        }

        return null;
    }

    /**
     * Release a partial acquisition: kill and reap the child, close every pipe and both stderr handles, unlink
     * the file. Each step is attempted on its own; none may throw out of here.
     *
     * @param  mixed  $process
     * @param  array<int, mixed>  $pipes
     * @param  mixed  $stderr
     * @param  mixed  $stderrRead
     */
    private static function abandon($process, array $pipes, $stderr, ?string $path, $stderrRead = null): void
    {
        try {
            if (is_resource($stderrRead)) {
                @fclose($stderrRead);
            }
        } catch (Throwable) {
        }

        foreach ($pipes as $pipe) {
            try {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            } catch (Throwable) {
            }
        }

        try {
            if (is_resource($process)) {
                @proc_terminate($process, 9);
                @proc_close($process);
            }
        } catch (Throwable) {
        }

        try {
            if (is_resource($stderr)) {
                @fclose($stderr);
            }
        } catch (Throwable) {
        }

        try {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        } catch (Throwable) {
        }
    }

    public function refusal(): ?Result
    {
        return null;
    }

    public function isRunning(): bool
    {
        $this->poll();
        $this->compact();

        return $this->process !== null && ! $this->exited;
    }

    public function pid(): ?int
    {
        return $this->pid;
    }

    public function sandboxed(): bool
    {
        return $this->sandboxed;
    }

    public function grant(): Grant
    {
        return $this->grant;
    }

    public function send(string $line): bool
    {
        $stdin = $this->pipes[0] ?? null;

        if ($this->result !== null || ! is_resource($stdin)) {
            return false;
        }

        $this->compact();
        $frame = str_ends_with($line, "\n") ? $line : $line."\n";
        $written = @fwrite($stdin, $frame);

        if ($written !== strlen($frame)) {
            return false;
        }

        return @fflush($stdin);
    }

    public function receive(): ?string
    {
        $stdout = $this->pipes[1] ?? null;

        if ($this->result !== null || ! is_resource($stdout)) {
            return null;
        }

        $line = fgets($stdout);
        $this->compact();

        if ($line === false) {
            $this->poll();

            return null;
        }

        return rtrim($line, "\r\n");
    }

    public function close(): Result
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $hostEnded = false;
        $stderr = '';
        $observed = $this->stderrObserved;
        $omitted = 0;
        $stderrUnread = false;
        $readError = null;

        try {
            if (is_resource($this->pipes[0] ?? null)) {
                @fclose($this->pipes[0]);
            }

            $process = $this->process;

            if (is_resource($process) && ! $this->waitForExit()) {
                $hostEnded = true;
                @proc_terminate($process, 15);

                if (! $this->waitForExit()) {
                    @proc_terminate($process, 9);
                    $this->waitForExit();
                }
            }

            if (is_resource($this->pipes[1] ?? null)) {
                @fclose($this->pipes[1]);
            }

            if (is_resource($this->process)) {
                $code = proc_close($this->process);

                // proc_close reports the code only when proc_get_status has not already consumed it.
                if (! $this->exited && $code !== -1) {
                    $this->exited = true;
                    $this->exitCode = $code;
                }
            }

            [$stderr, $observed, $omitted] = $this->finalStderr();
        } catch (Throwable $e) {
            $readError = 'popcorn: closing the session failed ('.$e::class.': '.$e->getMessage().')';
            // The final stderr is UNKNOWN, never empty-and-complete: say so explicitly, mark it truncated, and count
            // everything observed so far as omitted (both still lower bounds; the final epoch is not counted).
            $stderrUnread = true;
            $omitted = $observed;
            $stderr = sprintf(
                "[popcorn: stderr could not be read at close; at least %d of at least %d observed bytes omitted; 0 bytes retained]\n",
                $omitted,
                $observed,
            );
        } finally {
            self::abandon($this->process, $this->pipes, $this->stderr, $this->stderrFile, $this->stderrRead);
            $this->process = null;
            $this->pipes = [];
            $this->stderr = null;
            $this->stderrRead = null;
            $this->stderrHeld = '';

            $wallMs = (int) round((microtime(true) - $this->startedAt) * 1000);
            $clean = $readError === null && ($hostEnded || $this->exitCode === 0);

            $this->result = new Result(
                $clean ? Outcome::Success : Outcome::NonZeroExit,
                stderr: $stderr,
                stderrTruncated: $omitted > 0 || $this->stderrLossPossible || $stderrUnread,
                error: $clean ? null : ($readError ?? $this->failureMessage($stderr)),
                wallMs: $wallMs,
                exitCode: $this->exitCode,
                signal: $this->signal,
                sandboxed: $this->sandboxed,
                stderrBytesObservedAtLeast: $observed,
                stderrBytesOmittedAtLeast: $omitted,
                stderrLossPossible: $this->stderrLossPossible,
            );
        }

        return $this->result;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** The stderr file's current size, read through the host's own handle; null when it cannot be read. */
    private function stderrSize(): ?int
    {
        if (! is_resource($this->stderrRead)) {
            return null;
        }

        $stat = fstat($this->stderrRead);

        return is_array($stat) ? (int) $stat['size'] : null;
    }

    /**
     * Bytes `[max(0, size − STDERR_TAIL_BYTES), size)` of the current epoch, read through the host's own handle (its
     * offset is never moved by the child's appends). Exactly up to `$size`, so the bytes read are the ones counted.
     * Null when the read fails.
     */
    private function readLast(int $size): ?string
    {
        if (! is_resource($this->stderrRead)) {
            return null;
        }

        $from = max(0, $size - self::STDERR_TAIL_BYTES);

        if (fseek($this->stderrRead, $from) !== 0) {
            return null;
        }

        $this->probe('after-seek');

        // The probe is a callback and may have re-entered close(), which closes and clears this handle: re-read it and
        // guard it again. A handle gone here is a failed read (null), exactly like a failed seek.
        $read = $this->stderrRead;

        if (! is_resource($read)) {
            return null;
        }

        $length = $size - $from;
        $bytes = $length > 0 ? stream_get_contents($read, $length) : '';

        return is_string($bytes) ? $bytes : null;
    }

    /**
     * Past STDERR_COMPACT_BYTES, start a new epoch: keep the epoch's last STDERR_TAIL_BYTES in host memory, then truncate
     * the file to 0. Nothing is written back, so the child's next append begins the new epoch in true order. Whatever
     * the child (or a descendant sharing its stderr) writes between the size read and the truncate is destroyed unseen,
     * and that window cannot be observed, so every compaction sets the loss-possible flag. A failed read or truncate
     * changes nothing.
     */
    private function compact(): void
    {
        if ($this->result !== null || ! is_resource($this->stderr)) {
            return;
        }

        try {
            $size = $this->stderrSize();

            if ($size === null || $size <= self::STDERR_COMPACT_BYTES) {
                return;
            }

            $this->probe('after-stat');
            $tail = $this->readLast($size);

            // Callbacks ran since the guard above (the probe, and readLast's): re-read the write handle and guard it
            // again. A handle closed or cleared meanwhile means no truncate, so nothing is lost or counted.
            $write = $this->stderr;

            if ($tail === null || ! is_resource($write) || ! ftruncate($write, 0)) {
                return;
            }

            $this->stderrHeld = $tail;
            $this->stderrObserved += $size;
            $this->stderrEpochs++;
            $this->stderrLossPossible = true;
            $this->probe('after-truncate');
        } catch (Throwable) {
            // Nothing was truncated, or the epoch was already accounted: close() still returns a bounded result.
        }
    }

    /**
     * What close() returns: [stderr, observedAtLeast, omittedAtLeast].
     *
     * The child's bytes are the last ones of `held ⧺ final epoch`, in write order, cut so that the whole string,
     * marker and gap lines included, is at most STDERR_TAIL_BYTES. A gap line separates held bytes from final-epoch
     * bytes, because a compaction's window may have lost bytes between them. Omitted = observed − returned child bytes,
     * so the final trim counts and no held copy is counted twice (it is never written back to the file).
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function finalStderr(): array
    {
        // An unknown size is never zero: it goes to close()'s explicit could-not-be-read result instead.
        $size = $this->stderrSize() ?? throw new \RuntimeException('the stderr size could not be read');
        $final = $size > 0 ? ($this->readLast($size) ?? throw new \RuntimeException('the stderr bytes could not be read')) : '';
        $observed = $this->stderrObserved + $size;
        $held = $this->stderrEpochs > 0 ? $this->stderrHeld : '';

        if (! $this->stderrLossPossible && $held === '' && $size <= self::STDERR_TAIL_BYTES && strlen($final) === $size) {
            return [$final, $observed, 0]; // everything observed, nothing omitted, no marker
        }

        $budget = self::STDERR_TAIL_BYTES - self::STDERR_MARKER_RESERVE;
        $keptFinal = strlen($final) > $budget ? substr($final, -$budget) : $final;
        $room = $budget - strlen($keptFinal);
        $keptHeld = $room <= 0 ? '' : (strlen($held) > $room ? substr($held, -$room) : $held);
        $retained = strlen($keptHeld) + strlen($keptFinal);
        $omitted = max(0, $observed - $retained);

        $marker = sprintf(
            "[popcorn: stderr truncated: at least %d of at least %d observed bytes omitted; %d bytes retained in write order%s]\n",
            $omitted,
            $observed,
            $retained,
            $this->stderrLossPossible ? '; bytes written during a compaction may have been lost unobserved' : '',
        );
        $gap = $keptHeld !== '' && $keptFinal !== '' ? self::STDERR_GAP : '';

        return [$marker.$keptHeld.$gap.$keptFinal, $observed, $omitted];
    }

    /** The @internal interleaving seam: tests only. */
    private function probe(string $point): void
    {
        if ($this->stderrProbe !== null && is_resource($this->stderr)) {
            ($this->stderrProbe)($point, $this->stderr);
        }
    }

    private function failureMessage(string $stderr): string
    {
        $what = $this->signal !== null ? "was killed by signal {$this->signal}" : "exited {$this->exitCode}";

        return trim($stderr) !== '' ? trim($this->tail($stderr, 2048)) : "popcorn: the session process {$what}.";
    }

    /**
     * Whether the child has exited within the grace period. Impure: it polls the child and the clock, so two calls in a
     * row can answer differently (the second follows a signal).
     *
     * @phpstan-impure
     */
    private function waitForExit(): bool
    {
        $deadline = microtime(true) + self::GRACE_MS / 1000;

        do {
            $this->poll();

            if ($this->exited) {
                return true;
            }

            usleep(self::POLL_US);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function poll(): void
    {
        if ($this->exited || ! is_resource($this->process)) {
            return;
        }

        $this->observe(proc_get_status($this->process));
    }

    /** @param  array<string, mixed>  $status */
    private function observe(array $status): void
    {
        if ($this->exited || ($status['running'] ?? true)) {
            return;
        }

        $this->exited = true;

        if ($status['signaled'] ?? false) {
            $this->signal = (int) $status['termsig'];
        } else {
            $this->exitCode = (int) $status['exitcode'];
        }
    }
}
