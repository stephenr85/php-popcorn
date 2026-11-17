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
 * - **Diagnostics are bounded** (amend, finding 4). `close()` reads only the last {@see STDERR_TAIL_BYTES}
 *   (seek from the file's size; never the whole file into memory), and every `send()`/`receive()`/
 *   `isRunning()` COMPACTS the file to that tail once it passes {@see STDERR_COMPACT_BYTES}. The child
 *   writes through an O_APPEND descriptor, so after a compaction it keeps appending at the new end.
 *   RESIDUAL BOUNDARY: compaction runs only on those host calls, so between two calls a child can grow the
 *   file on disk without limit, and bytes written between the compaction's tail read and its truncate
 *   are lost (`stderrTruncated` is then true). Memory use at close stays bounded either way.
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

    /** Once the stderr file passes this size, a host call compacts it to its last STDERR_TAIL_BYTES. */
    public const STDERR_COMPACT_BYTES = 1048576; // 1 MiB

    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    /** @var resource|null the O_APPEND handle shared with the child's stderr */
    private $stderr;

    private bool $exited = false;

    private ?int $exitCode = null;

    private ?int $signal = null;

    private ?Result $result = null;

    private bool $stderrDropped = false;

    private float $startedAt;

    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     * @param  resource  $stderr
     */
    private function __construct(
        $process,
        array $pipes,
        $stderr,
        private string $stderrFile,
        private bool $sandboxed,
        private Grant $grant,
        private ?int $pid,
    ) {
        $this->process = $process;
        $this->pipes = $pipes;
        $this->stderr = $stderr;
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
     */
    public static function start(
        array $argv,
        array $env,
        bool $sandboxed,
        Grant $grant,
        ?string $cwd = null,
        ?string $stderrDir = null,
        ?Closure $spawn = null,
    ): Session {
        $invalid = self::invalidLaunch($argv, $env, $cwd);

        if ($invalid !== null) {
            return new RefusedSession(Result::substrateUnavailable("popcorn: refused to launch a session: {$invalid}."), $grant);
        }

        $path = null;
        $stderr = null;
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
                self::abandon(null, $pipes, $stderr, $path);

                return new RefusedSession(Result::substrateUnavailable("popcorn: could not spawn `{$argv[0]}`."), $grant);
            }

            if (! is_resource($pipes[0] ?? null) || ! is_resource($pipes[1] ?? null)) {
                self::abandon($process, $pipes, $stderr, $path);

                return new RefusedSession(Result::substrateUnavailable("popcorn: `{$argv[0]}` started without both stdio pipes; it was stopped."), $grant);
            }

            $status = proc_get_status($process);
            $session = new self($process, $pipes, $stderr, $path, $sandboxed, $grant, $status['pid']);
            $session->observe($status);

            return $session;
        } catch (Throwable $e) {
            self::abandon($process, $pipes, $stderr, $path);

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
     * Release a partial acquisition: kill and reap the child, close every pipe and the stderr handle, unlink
     * the file. Each step is attempted on its own; none may throw out of here.
     *
     * @param  mixed  $process
     * @param  array<int, mixed>  $pipes
     * @param  mixed  $stderr
     */
    private static function abandon($process, array $pipes, $stderr, ?string $path): void
    {
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
        $truncated = $this->stderrDropped;
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

            [$stderr, $tailTruncated] = $this->readTail();
            $truncated = $truncated || $tailTruncated;
        } catch (Throwable $e) {
            $readError = 'popcorn: closing the session failed ('.$e::class.': '.$e->getMessage().')';
        } finally {
            self::abandon($this->process, $this->pipes, $this->stderr, $this->stderrFile);
            $this->process = null;
            $this->pipes = [];
            $this->stderr = null;

            $wallMs = (int) round((microtime(true) - $this->startedAt) * 1000);
            $clean = $readError === null && ($hostEnded || $this->exitCode === 0);

            $this->result = new Result(
                $clean ? Outcome::Success : Outcome::NonZeroExit,
                stderr: $stderr,
                stderrTruncated: $truncated,
                error: $clean ? null : ($readError ?? $this->failureMessage($stderr)),
                wallMs: $wallMs,
                exitCode: $this->exitCode,
                signal: $this->signal,
                sandboxed: $this->sandboxed,
            );
        }

        return $this->result;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * The last STDERR_TAIL_BYTES of the stderr file, read by seeking from its size: memory stays bounded however
     * large the file grew. Returns [tail, whether anything before it exists].
     *
     * @return array{0: string, 1: bool}
     */
    private function readTail(): array
    {
        if (! is_resource($this->stderr)) {
            return ['', false];
        }

        $size = (int) (fstat($this->stderr)['size'] ?? 0);
        $from = max(0, $size - self::STDERR_TAIL_BYTES);

        if (fseek($this->stderr, $from) !== 0) {
            return ['', $size > 0];
        }

        $tail = stream_get_contents($this->stderr, self::STDERR_TAIL_BYTES);

        return [is_string($tail) ? $tail : '', $from > 0];
    }

    /** Keep the stderr file bounded across a long session: past STDERR_COMPACT_BYTES, keep only its tail. */
    private function compact(): void
    {
        if ($this->result !== null || ! is_resource($this->stderr)) {
            return;
        }

        try {
            $size = (int) (fstat($this->stderr)['size'] ?? 0);

            if ($size <= self::STDERR_COMPACT_BYTES) {
                return;
            }

            [$tail] = $this->readTail();

            if (ftruncate($this->stderr, 0)) {
                fwrite($this->stderr, $tail); // O_APPEND: lands at the new end, as the child's next write will
                fflush($this->stderr);
                $this->stderrDropped = true;
            }
        } catch (Throwable) {
            // A failed compaction loses nothing the session needs; close() still reads a bounded tail.
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
