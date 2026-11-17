<?php

use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\ProcessSession;
use Rushing\Popcorn\Runner\RefusedSession;

/**
 * Amend for Codex review ff28ff40 (prr03), findings 2 and 4: a session launch is TOTAL (every failure, thrown or
 * returned, comes back as a refusal and leaves nothing behind) and its diagnostics are BOUNDED (close never reads the
 * whole stderr file; a long session compacts it).
 *
 * Every acquisition test injects its failure at a real stage of start(), in its own private stderr directory, and
 * then proves the directory is EMPTY afterwards, so a leaked file cannot hide in a shared temp dir.
 */
function acquisitionDir(): string
{
    $dir = sys_get_temp_dir().'/popcorn-acq-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);

    return $dir;
}

/** @return list<string> */
function leftIn(string $dir): array
{
    return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
}

function childArgv(string $mode = 'echo'): array
{
    return [PHP_BINARY, '-n', __DIR__.'/../../Fixtures/session/child.php', $mode];
}

function pidIsAlive(int $pid): bool
{
    // posix_kill(0) is not always available; `kill -0` is the portable probe.
    exec('kill -0 '.(int) $pid.' 2>/dev/null', $out, $code);

    return $code === 0;
}

afterEach(function () {
    foreach ($GLOBALS['acquisitionDirs'] ?? [] as $dir) {
        foreach (leftIn($dir) as $f) {
            @unlink("{$dir}/{$f}");
        }
        @rmdir($dir);
    }
    $GLOBALS['acquisitionDirs'] = [];
});

function trackedDir(): string
{
    $dir = acquisitionDir();
    $GLOBALS['acquisitionDirs'][] = $dir;

    return $dir;
}

// --- finding 2: total, leak-free acquisition ----------------------------------------------------------------------

it('refuses a NUL byte in argv, env or cwd before acquiring anything (proc_open would throw ValueError)', function (array $argv, array $env, ?string $cwd) {
    $dir = trackedDir();

    $session = ProcessSession::start($argv, $env, false, Grant::none(), $cwd, $dir);

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->outcome)->toBe(Outcome::SubstrateUnavailable)
        ->and(leftIn($dir))->toBe([]);
})->with([
    'argv' => [[PHP_BINARY, "/pkg/entry\0.js"], [], null],
    'env value' => [[PHP_BINARY, '-v'], ['LLM_KEY' => "k\0"], null],
    'env name' => [[PHP_BINARY, '-v'], ["BAD\0NAME" => 'v'], null],
    'env name with =' => [[PHP_BINARY, '-v'], ['A=B' => 'v'], null],
    'cwd' => [[PHP_BINARY, '-v'], [], "/tmp\0x"],
    'empty interpreter' => [['', 'x'], [], null],
]);

it('refuses when the stderr file cannot be created, even under an error handler that throws on warnings', function () {
    $missing = sys_get_temp_dir().'/popcorn-acq-missing-'.bin2hex(random_bytes(6));
    // Laravel's HandleExceptions converts warnings to ErrorException; emulate it for the whole launch.
    set_error_handler(static function (int $no, string $message): bool {
        throw new ErrorException($message, 0, $no);
    });

    try {
        $session = ProcessSession::start(childArgv(), [], false, Grant::none(), null, $missing);
    } finally {
        restore_error_handler();
    }

    // The throwing handler turns fopen's warning into an ErrorException inside start(): it must still come back
    // as a refusal (never escape), and nothing may have been created anywhere.
    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->outcome)->toBe(Outcome::SubstrateUnavailable)
        ->and(is_dir($missing))->toBeFalse();
});

it('turns a spawn that THROWS into a refusal and unlinks the stderr file it had created', function () {
    $dir = trackedDir();

    $session = ProcessSession::start(childArgv(), [], false, Grant::none(), null, $dir,
        static fn () => throw new RuntimeException('injected spawn failure'));

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->error)->toContain('injected spawn failure')
        ->and(leftIn($dir))->toBe([]);
});

it('turns a spawn that returns no process into a refusal and unlinks the stderr file', function () {
    $dir = trackedDir();

    $session = ProcessSession::start(childArgv(), [], false, Grant::none(), null, $dir, static fn () => [false, []]);

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->error)->toContain('could not spawn')
        ->and(leftIn($dir))->toBe([]);
});

// --- PRR03 unit 2 amend (fresh Claude review, blocking): the DEFAULT spawn failing, with no injected seam --------
// A missing absolute binary reaches the real proc_open. Which path that takes is the platform's: posix_spawn refuses
// it (proc_open returns false WITHOUT assigning $pipes), or a fork succeeds and the child exits 127. Each case asserts
// its own path; on the other path it still proves nothing was thrown or left, then says which path this platform took.

function missingBinary(): string
{
    return '/nonexistent/popcorn-default-spawn-'.bin2hex(random_bytes(4));
}

it('DEFAULT spawn, posix_spawn path: a binary the spawn refuses is a refusal, nothing thrown, nothing left', function () {
    $dir = trackedDir();

    $session = ProcessSession::start([missingBinary()], [], false, Grant::none(), null, $dir);

    if ($session->refusal() === null) {
        $session->close();
        expect(leftIn($dir))->toBe([]);
        $this->markTestSkipped('PATH NOT TAKEN here: this platform started a child for the missing binary (fork/exec); the fork/exec case below asserts it.');
    }

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->outcome)->toBe(Outcome::SubstrateUnavailable)
        ->and($session->refusal()?->error)->toContain('could not spawn')
        ->and(leftIn($dir))->toBe([]);
});

it('DEFAULT spawn, fork/exec path: the child exits 127, closes as NonZeroExit, nothing thrown, nothing left', function () {
    $dir = trackedDir();

    $session = ProcessSession::start([missingBinary()], [], false, Grant::none(), null, $dir);

    if ($session->refusal() !== null) {
        expect(leftIn($dir))->toBe([]);
        $this->markTestSkipped('PATH NOT TAKEN here: this platform refused the missing binary at spawn (posix_spawn); the posix_spawn case above asserts it.');
    }

    $pid = $session->pid();
    $result = $session->close();

    expect($result->outcome)->toBe(Outcome::NonZeroExit)
        ->and($result->exitCode)->toBe(127)
        ->and($pid)->toBeInt()
        ->and(pidIsAlive((int) $pid))->toBeFalse()
        ->and(leftIn($dir))->toBe([]);
});

it('releases a PARTIAL acquisition: a started child without both pipes is killed and reaped, nothing is left', function () {
    $dir = trackedDir();
    $pid = null;

    $session = ProcessSession::start(childArgv('ignore'), [], false, Grant::none(), null, $dir,
        static function (array $argv, array $descriptors, ?string $cwd, array $env) use (&$pid): array {
            $process = proc_open($argv, $descriptors, $pipes, $cwd, $env);
            $pid = proc_get_status($process)['pid'];
            unset($pipes[1]); // the stdout pipe is "lost": a partial acquisition

            return [$process, $pipes];
        });

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->error)->toContain('without both stdio pipes')
        ->and($pid)->toBeInt()
        ->and(pidIsAlive($pid))->toBeFalse()
        ->and(leftIn($dir))->toBe([]);
});

// --- PRR03 P4 (root cb7f7936): the host's stderr READ handle is acquired after the spawn ------------------------------

/** The number of open descriptors in this process, so a test can prove every handle it caused was released. */
function openDescriptorCount(): int
{
    return count(scandir('/dev/fd') ?: []);
}

it('releases a PARTIAL acquisition when the stderr read handle cannot be opened after the spawn: child reaped, nothing left', function () {
    $dir = trackedDir();
    $pid = null;
    $before = openDescriptorCount();

    // The existing spawn seam really spawns the child, then unlinks the stderr file the descriptor points at, so the
    // read-handle fopen($path, 'r') that follows fails with ENOENT deterministically (even as root). No new seam.
    $session = ProcessSession::start(childArgv('ignore'), [], false, Grant::none(), null, $dir,
        static function (array $argv, array $descriptors, ?string $cwd, array $env) use (&$pid): array {
            $process = proc_open($argv, $descriptors, $pipes, $cwd, $env);
            $pid = proc_get_status($process)['pid'];
            unlink(stream_get_meta_data($descriptors[2])['uri']);

            return [$process, $pipes];
        });

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->outcome)->toBe(Outcome::SubstrateUnavailable)
        ->and($session->refusal()?->error)->toContain('could not open the stderr file for reading')
        ->and($pid)->toBeInt()
        ->and(pidIsAlive($pid))->toBeFalse()
        ->and(leftIn($dir))->toBe([])
        ->and(openDescriptorCount())->toBe($before);
});

it('releases BOTH stderr handles on every other acquisition failure', function (Closure $spawn) {
    $dir = trackedDir();
    $before = openDescriptorCount();

    $session = ProcessSession::start(childArgv('ignore'), [], false, Grant::none(), null, $dir, $spawn);

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and(leftIn($dir))->toBe([])
        ->and(openDescriptorCount())->toBe($before);
})->with([
    'a throwing spawn' => [static fn () => throw new RuntimeException('injected')],
    'a spawn with no process' => [static fn () => [false, []]],
    'a started child without both pipes' => [static function (array $argv, array $descriptors, ?string $cwd, array $env): array {
        $process = proc_open($argv, $descriptors, $pipes, $cwd, $env);
        fclose($pipes[1]);
        unset($pipes[1]);

        return [$process, $pipes];
    }],
]);

it('leaves nothing behind after a normal session closes', function () {
    $dir = trackedDir();

    $session = ProcessSession::start(childArgv(), [], false, Grant::none(), null, $dir);
    expect(leftIn($dir))->toHaveCount(1); // the live stderr file

    $session->send('hi');
    $session->receive();
    $session->close();

    expect(leftIn($dir))->toBe([]);
});

// --- finding 4: bounded diagnostics ---------------------------------------------------------------------------------

it('reads only a bounded tail at close: memory stays flat after a 6 MiB stderr flood with no host call in between', function () {
    $dir = trackedDir();
    $session = ProcessSession::start(childArgv(), [], false, Grant::none(), null, $dir);

    // flood-exit: the child floods stderr and leaves WITHOUT answering, so no send/receive/isRunning (and so no
    // compaction) happens between the flood and close(): close alone must stay bounded.
    expect($session->send('flood-exit 6291456'))->toBeTrue();
    $file = $dir.'/'.leftIn($dir)[0];
    $deadline = microtime(true) + 10;
    do {
        clearstatcache(true, $file);
        $grown = filesize($file) > 6291456;
        if (! $grown) {
            usleep(20000);
        }
    } while (! $grown && microtime(true) < $deadline);
    expect(filesize($file))->toBeGreaterThan(6291456);

    memory_reset_peak_usage();
    $base = memory_get_usage();
    $result = $session->close();
    $peakDelta = memory_get_peak_usage() - $base;

    expect($result->outcome)->toBe(Outcome::Success)
        ->and(strlen($result->stderr))->toBeLessThanOrEqual(16384)
        ->and($result->stderr)->toEndWith("END-MARKER\n")
        ->and($result->stderrTruncated)->toBeTrue()
        ->and($peakDelta)->toBeLessThan(1048576)
        ->and(leftIn($dir))->toBe([]);
});

it('compacts the stderr file on host calls once it passes the threshold, keeping the latest tail', function () {
    $dir = trackedDir();
    $session = ProcessSession::start(childArgv(), [], false, Grant::none(), null, $dir);

    expect($session->send('flood 3145728'))->toBeTrue()
        ->and($session->receive())->toBe('flooded');   // receive() compacts after the reply

    $file = $dir.'/'.leftIn($dir)[0];
    clearstatcache(true, $file);
    expect(filesize($file))->toBeLessThanOrEqual(ProcessSession::STDERR_COMPACT_BYTES);

    expect($session->send('after'))->toBeTrue()->and($session->receive())->toBe('echo:after');
    $result = $session->close();

    expect($result->stderrTruncated)->toBeTrue()
        ->and($result->stderr)->toContain('END-MARKER')
        ->and(strlen($result->stderr))->toBeLessThanOrEqual(16384);
});
