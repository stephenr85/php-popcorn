<?php

use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\ProcessSession;
use Rushing\Popcorn\Runner\Result;

/**
 * PRR03 P4 (root `cb7f7936`, plan PLAN-P4 revision 2): retained stderr is capped per host call, with an explicit
 * marker and LOWER-bound counts, and no silent loss.
 *
 * Every interleaving here is INSTRUMENTED, not raced. The child is the `echo` fixture: `flood N` writes N bytes of 'x'
 * plus "\nEND-MARKER\n" to stderr, flushes, and only then answers `flooded` on stdout. So once the host has received
 * `flooded`, the child is idle (blocked on stdin) and writes nothing more. The `@internal` probe seam writes bytes
 * through the child's own O_APPEND handle at an exact point of a compaction (`after-stat`, `after-seek`,
 * `after-truncate`). The injected races are deterministic: no tuned delays and no repeat loops. The one wait is the
 * non-claim pin's bounded synchronisation (a file-size condition with a deadline, the same pattern as the existing
 * 6 MiB test), not a race. Exact byte counts are test knowledge; the product still names them lower bounds.
 */
const P4_FLOOD = 1200000;                 // > STDERR_COMPACT_BYTES (1 MiB): one host call after it compacts
const P4_TAIL_END = "\nEND-MARKER\n";      // what the fixture appends after the flood
const P4_FLOOD_TOTAL = P4_FLOOD + 12;      // bytes one `flood P4_FLOOD` writes
const P4_TAIL = 16384;                     // STDERR_TAIL_BYTES

function p4Dir(): string
{
    $dir = sys_get_temp_dir().'/popcorn-p4-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    $GLOBALS['p4Dirs'][] = $dir;

    return $dir;
}

/** @return list<string> */
function p4Left(string $dir): array
{
    return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
}

function p4Session(string $dir, ?Closure $probe = null): Rushing\Popcorn\Runner\Session
{
    return ProcessSession::start(
        [PHP_BINARY, '-n', __DIR__.'/../../Fixtures/session/child.php', 'echo'],
        [],
        false,
        Grant::none(),
        null,
        $dir,
        null,
        $probe,
    );
}

/** A probe that writes `$bytes` through the child's write handle the FIRST time `$at` is reached, and never again. */
function p4InjectOnce(string $at, string $bytes): Closure
{
    $done = false;

    return static function (string $point, $write) use ($at, $bytes, &$done): void {
        if (! $done && $point === $at) {
            $done = true;
            fwrite($write, $bytes);
            fflush($write);
        }
    };
}

/** The child's bytes in a Result's stderr: everything after the marker line, when there is one. */
function p4ChildBytes(string $stderr): string
{
    return str_starts_with($stderr, '[popcorn: stderr truncated:') ? substr($stderr, strpos($stderr, "\n") + 1) : $stderr;
}

/** The marker's three numbers: [omitted N, observed M, retained R]. */
function p4MarkerNumbers(string $stderr): array
{
    expect(preg_match('/^\[popcorn: stderr truncated: at least (\d+) of at least (\d+) observed bytes omitted; (\d+) bytes retained in write order/', $stderr, $m))->toBe(1);

    return [(int) $m[1], (int) $m[2], (int) $m[3]];
}

function p4Flood(Rushing\Popcorn\Runner\Session $session, int $bytes = P4_FLOOD): void
{
    expect($session->send("flood {$bytes}"))->toBeTrue()
        ->and($session->receive())->toBe('flooded'); // receive() compacts after the reply, the child now idle
}

afterEach(function () {
    foreach ($GLOBALS['p4Dirs'] ?? [] as $dir) {
        foreach (p4Left($dir) as $f) {
            @unlink("{$dir}/{$f}");
        }
        @rmdir($dir);
    }
    $GLOBALS['p4Dirs'] = [];
});

it('loses bytes written in the stat-to-truncate window, and says so: possible loss flagged, counts a lower bound', function () {
    $dir = p4Dir();
    $session = p4Session($dir, p4InjectOnce('after-stat', "LOST-IN-WINDOW\n"));

    p4Flood($session);
    $result = $session->close();

    expect($result->stderr)->not->toContain('LOST-IN-WINDOW')
        ->and($result->stderrLossPossible)->toBeTrue()
        ->and($result->stderrTruncated)->toBeTrue()
        ->and($result->stderr)->toStartWith('[popcorn: stderr truncated: at least ')
        ->and($result->stderr)->toContain('bytes written during a compaction may have been lost unobserved')
        // The 15 injected bytes were never observed: the count is a lower bound, never padded.
        ->and($result->stderrBytesObservedAtLeast)->toBe(P4_FLOOD_TOTAL)
        ->and(strlen($result->stderr))->toBeLessThanOrEqual(P4_TAIL)
        ->and(p4Left($dir))->toBe([]);

    // The marker's numbers are the Result's numbers, and R is the child bytes actually returned (no gap line here).
    $retained = strlen(p4ChildBytes($result->stderr));
    expect($result->stderrBytesOmittedAtLeast)->toBe(P4_FLOOD_TOTAL - $retained)
        ->and(p4MarkerNumbers($result->stderr))->toBe([$result->stderrBytesOmittedAtLeast, $result->stderrBytesObservedAtLeast, $retained]);

    // Close is cached: the same Result, with the same counts, after a compaction.
    expect($session->close())->toBe($result)
        ->and($session->close()->stderrBytesOmittedAtLeast)->toBe(P4_FLOOD_TOTAL - $retained);
});

it('never reorders: bytes appended right after the truncate follow the held tail, after a gap line', function () {
    $dir = p4Dir();
    $session = p4Session($dir, p4InjectOnce('after-truncate', "AFTER-TRUNCATE\n"));

    p4Flood($session);
    $result = $session->close();
    $child = p4ChildBytes($result->stderr);

    $end = strpos($child, P4_TAIL_END);
    $gap = strpos($child, "[popcorn: possible gap]\n");
    $after = strpos($child, "AFTER-TRUNCATE\n");

    expect($end)->toBeInt()->and($gap)->toBeInt()->and($after)->toBeInt()
        ->and($end < $gap && $gap < $after)->toBeTrue()
        ->and($child)->toEndWith("AFTER-TRUNCATE\n")
        ->and($result->stderrBytesObservedAtLeast)->toBe(P4_FLOOD_TOTAL + strlen("AFTER-TRUNCATE\n"))
        ->and($result->stderrLossPossible)->toBeTrue()
        ->and(strlen($result->stderr))->toBeLessThanOrEqual(P4_TAIL);

    // Retained child bytes exclude the gap line; the marker's numbers agree with the Result's.
    $retained = strlen($child) - strlen("[popcorn: possible gap]\n");
    expect($result->stderrBytesOmittedAtLeast)->toBe($result->stderrBytesObservedAtLeast - $retained)
        ->and(p4MarkerNumbers($result->stderr))->toBe([$result->stderrBytesOmittedAtLeast, $result->stderrBytesObservedAtLeast, $retained]);
});

it('reads through its own handle: an append between the seek and the read cannot move what the host reads', function () {
    $dir = p4Dir();
    // On a handle shared with the child, this append would move the read offset to EOF before the read.
    $session = p4Session($dir, p4InjectOnce('after-seek', "MOVED-OFFSET\n"));

    p4Flood($session);
    $result = $session->close();
    $child = p4ChildBytes($result->stderr);

    // The held tail is exactly the flood's last bytes: 'x' then END-MARKER, nothing injected inside it.
    expect($child)->toEndWith(P4_TAIL_END)
        ->and(strlen($child))->toBeGreaterThan(P4_TAIL - 512 - 1)
        ->and(rtrim(substr($child, 0, -strlen(P4_TAIL_END)), 'x'))->toBe('')
        ->and($result->stderr)->not->toContain('MOVED-OFFSET')
        ->and($result->stderrLossPossible)->toBeTrue()
        ->and($result->stderrBytesObservedAtLeast)->toBe(P4_FLOOD_TOTAL)
        ->and($result->stderrBytesOmittedAtLeast)->toBe(P4_FLOOD_TOTAL - strlen($child));
});

it('counts each epoch once: two compactions, then close, observe the sum, and nothing held is recounted', function () {
    $dir = p4Dir();
    $session = p4Session($dir);

    p4Flood($session);
    p4Flood($session);
    $result = $session->close();
    $retained = strlen(p4ChildBytes($result->stderr));

    expect($result->stderrBytesObservedAtLeast)->toBe(2 * P4_FLOOD_TOTAL)
        ->and($result->stderrBytesOmittedAtLeast)->toBe(2 * P4_FLOOD_TOTAL - $retained)
        ->and($result->stderr)->not->toContain('[popcorn: possible gap]') // nothing was written after the last truncate
        ->and(strlen($result->stderr))->toBeLessThanOrEqual(P4_TAIL);
});

it('counts the final trim at close as omitted, with a marker but no loss clause when nothing was compacted', function () {
    $dir = p4Dir();
    $session = p4Session($dir);

    p4Flood($session, 100000); // over the tail, under the compaction threshold: no compaction
    $result = $session->close();
    $retained = strlen(p4ChildBytes($result->stderr));

    expect($result->stderrLossPossible)->toBeFalse()
        ->and($result->stderrBytesObservedAtLeast)->toBe(100000 + 12)
        ->and($result->stderrBytesOmittedAtLeast)->toBe(100000 + 12 - $retained)
        ->and($result->stderrTruncated)->toBeTrue()
        ->and($result->stderr)->toStartWith('[popcorn: stderr truncated: at least ')
        ->and($result->stderr)->not->toContain('may have been lost unobserved')
        ->and($result->stderr)->toEndWith(P4_TAIL_END)
        ->and(strlen($result->stderr))->toBeLessThanOrEqual(P4_TAIL);
});

it('returns a quiet child its whole stderr, exactly, with no marker and nothing omitted', function () {
    $dir = p4Dir();
    $session = p4Session($dir);

    p4Flood($session, 1000);
    $result = $session->close();

    expect($result->stderr)->toBe(str_repeat('x', 1000).P4_TAIL_END)
        ->and($result->stderrBytesObservedAtLeast)->toBe(1012)
        ->and($result->stderrBytesOmittedAtLeast)->toBe(0)
        ->and($result->stderrLossPossible)->toBeFalse()
        ->and($result->stderrTruncated)->toBeFalse();
});

it('caps per host call, not continuously: the file grows past the threshold between calls, and one call empties it', function () {
    $dir = p4Dir();
    $session = p4Session($dir);
    expect($session->send('flood 2097152'))->toBeTrue();

    // Wait for the child to finish writing (a condition with a deadline, not a tuned delay): no host call yet.
    $file = $dir.'/'.p4Left($dir)[0];
    $deadline = microtime(true) + 10;
    do {
        clearstatcache(true, $file);
        $written = filesize($file) >= 2097152 + 12;
        if (! $written) {
            usleep(20000);
        }
    } while (! $written && microtime(true) < $deadline);

    expect(filesize($file))->toBeGreaterThan(ProcessSession::STDERR_COMPACT_BYTES); // NOT a continuous disk bound

    expect($session->receive())->toBe('flooded'); // one host call: it compacts
    clearstatcache(true, $file);
    expect(filesize($file))->toBe(0);

    $session->close();
});

it('never reports an unreadable stderr at close as empty and complete: explicit line, truncated, lower-bound counts', function () {
    $dir = p4Dir();
    $seeks = 0;
    // The first after-seek is the compaction's read; the second is close()'s read, which fails here.
    $session = p4Session($dir, static function (string $point) use (&$seeks): void {
        if ($point === 'after-seek' && ++$seeks === 2) {
            throw new RuntimeException('injected read failure at close');
        }
    });

    p4Flood($session);
    p4Flood($session, 10); // a small final epoch (no compaction), so close() has bytes to read and reaches its seek
    $result = $session->close();

    expect($result->stderr)->toStartWith('[popcorn: stderr could not be read at close; ')
        ->and($result->stderrTruncated)->toBeTrue()
        ->and($result->stderrLossPossible)->toBeTrue()
        ->and($result->stderrBytesObservedAtLeast)->toBe(P4_FLOOD_TOTAL)
        ->and($result->stderrBytesOmittedAtLeast)->toBe(P4_FLOOD_TOTAL)
        ->and($result->outcome)->toBe(Outcome::NonZeroExit)
        ->and($result->error)->toContain('closing the session failed')
        ->and($session->close())->toBe($result)
        ->and(p4Left($dir))->toBe([]);
});

it('releases a session that compacted but was never closed: the destructor leaves nothing behind', function () {
    $dir = p4Dir();
    $session = p4Session($dir);
    p4Flood($session);

    unset($session); // the only reference: __destruct closes it

    expect(p4Left($dir))->toBe([]);
});

it('leaves a one-shot Result serialised exactly as before, and adds the counts only to a session Result', function () {
    $oneShot = Result::success('{"a":1}')->toArray();

    expect(array_keys($oneShot))->toBe(['outcome', 'error', 'output', 'stderr', 'stderrTruncated', 'telemetry', 'deniedAxis', 'deniedTarget']);

    $dir = p4Dir();
    $session = p4Session($dir);
    p4Flood($session, 10);
    $session = $session->close()->toArray();

    expect($session)->toHaveKeys(['stderrBytesObservedAtLeast', 'stderrBytesOmittedAtLeast', 'stderrLossPossible'])
        ->and($session['outcome'])->toBe(Outcome::Success->value);
});
