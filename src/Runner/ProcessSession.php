<?php

namespace Rushing\Popcorn\Runner;

use Rushing\Popcorn\Contracts\SessionRunner;
use Rushing\Popcorn\Runner\Concerns\HandlesRunnerIo;

/**
 * A live {@see Session} over one `proc_open` child: stdin and stdout are pipes, stderr goes to a
 * private temp file (so a chatty child can never fill a pipe nobody reads and wedge itself), and the
 * environment is EXACTLY the one the {@see SessionRunner} hands in: there is no host passthrough.
 *
 * The substrate decides the argv (a `bwrap … --` prefix under the bubble Runner, the bare interpreter
 * under a dev degrade) and whether that counts as `sandboxed`; this class only runs it and keeps the
 * lifecycle honest:
 *
 * - The child's exit status is captured the FIRST time it is seen (`proc_get_status` reports the exit
 *   code exactly once), so a session that died by itself closes as `NonZeroExit` with its real code.
 * - {@see close()} closes stdin first, gives the child a grace period to leave on its own, then
 *   SIGTERM, then SIGKILL. A child the host had to stop closes as `Success` with the signal recorded:
 *   ending a session is the host's call, not the child's failure.
 * - `close()` is idempotent and also runs from the destructor, so an abandoned session never leaks a
 *   process or a temp file.
 *
 * @api
 */
class ProcessSession implements Session
{
    use HandlesRunnerIo;

    /** How long close() waits after each step (stdin EOF, SIGTERM) before escalating. */
    private const GRACE_MS = 2000;

    private const POLL_US = 10000;

    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private bool $exited = false;

    private ?int $exitCode = null;

    private ?int $signal = null;

    private ?Result $result = null;

    private float $startedAt;

    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private function __construct(
        $process,
        array $pipes,
        private string $stderrFile,
        private bool $sandboxed,
        private Grant $grant,
        private ?int $pid,
    ) {
        $this->process = $process;
        $this->pipes = $pipes;
        $this->startedAt = microtime(true);
    }

    /**
     * Launch `$argv` with exactly `$env` and return the live session, or a {@see RefusedSession} when
     * the launch itself could not happen (no temp file for stderr, `proc_open` failed). A binary that
     * cannot be executed is not a refusal: the child starts, exits 127, and the session closes as
     * `NonZeroExit`.
     *
     * @param  list<string>  $argv
     * @param  array<string, string>  $env
     */
    public static function start(array $argv, array $env, bool $sandboxed, Grant $grant, ?string $cwd = null): Session
    {
        if ($argv === []) {
            return new RefusedSession(Result::substrateUnavailable('popcorn: a session needs a non-empty argv.'), $grant);
        }

        $stderrFile = tempnam(sys_get_temp_dir(), 'popcorn-session-err-');

        if ($stderrFile === false) {
            return new RefusedSession(Result::substrateUnavailable('popcorn: could not create the session stderr file.'), $grant);
        }

        $process = @proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']],
            $pipes,
            $cwd,
            $env,
        );

        if (! is_resource($process)) {
            @unlink($stderrFile);

            return new RefusedSession(Result::substrateUnavailable("popcorn: could not spawn `{$argv[0]}`."), $grant);
        }

        $status = proc_get_status($process);

        $session = new self($process, $pipes, $stderrFile, $sandboxed, $grant, $status['pid'] ?? null);
        $session->observe($status);

        return $session;
    }

    public function refusal(): ?Result
    {
        return null;
    }

    public function isRunning(): bool
    {
        $this->poll();

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

        if (is_resource($this->pipes[0] ?? null)) {
            @fclose($this->pipes[0]);
        }

        if (! $this->waitForExit()) {
            $hostEnded = true;
            @proc_terminate($this->process, 15);

            if (! $this->waitForExit()) {
                @proc_terminate($this->process, 9);
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

        $this->process = null;
        $this->pipes = [];

        $stderr = is_file($this->stderrFile) ? (string) file_get_contents($this->stderrFile) : '';
        @unlink($this->stderrFile);

        $wallMs = (int) round((microtime(true) - $this->startedAt) * 1000);
        $clean = $hostEnded || $this->exitCode === 0;

        return $this->result = new Result(
            $clean ? Outcome::Success : Outcome::NonZeroExit,
            stderr: $this->tail($stderr, self::STDERR_TAIL_BYTES),
            stderrTruncated: strlen($stderr) > self::STDERR_TAIL_BYTES,
            error: $clean ? null : $this->failureMessage($stderr),
            wallMs: $wallMs,
            exitCode: $this->exitCode,
            signal: $this->signal,
            sandboxed: $this->sandboxed,
        );
    }

    public function __destruct()
    {
        $this->close();
    }

    private function failureMessage(string $stderr): string
    {
        $what = $this->signal !== null ? "was killed by signal {$this->signal}" : "exited {$this->exitCode}";

        return trim($stderr) !== '' ? trim($this->tail($stderr, 2048)) : "popcorn: the session process {$what}.";
    }

    /** Wait up to the grace period for the child to exit; true when it has. */
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
