<?php

use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Net;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\ProcessSession;
use Rushing\Popcorn\Runner\RefusedSession;
use Rushing\Popcorn\Runner\Result;
use Rushing\Popcorn\Runner\RuntimeEnvAdditions;

/**
 * The live session over a real child process (popcorn-runner-rehome ticket 03, php-popcorn ADR-0003).
 * These run a real PHP child on any OS: they prove the lifecycle and the exact environment, which are
 * host-side facts. They do NOT prove sandbox enforcement; that belongs to a substrate's own tests.
 */
function sessionChild(string $mode, array $env = [], bool $sandboxed = false, ?Grant $grant = null): Rushing\Popcorn\Runner\Session
{
    return ProcessSession::start(
        [PHP_BINARY, '-n', __DIR__.'/../../Fixtures/session/child.php', $mode],
        $env,
        $sandboxed,
        $grant ?? Grant::none(),
    );
}

it('carries line frames both ways and closes cleanly when the child leaves at stdin EOF', function () {
    $session = sessionChild('echo');

    expect($session->refusal())->toBeNull()
        ->and($session->isRunning())->toBeTrue()
        ->and($session->pid())->toBeInt();

    expect($session->send('{"id":1}'))->toBeTrue()
        ->and($session->receive())->toBe('echo:{"id":1}')
        ->and($session->send("second\n"))->toBeTrue()
        ->and($session->receive())->toBe('echo:second');

    $result = $session->close();

    expect($result->outcome)->toBe(Outcome::Success)
        ->and($result->exitCode)->toBe(0)
        ->and($result->signal)->toBeNull()
        ->and($result->sandboxed)->toBeFalse()
        ->and($result->wallMs)->toBeInt()
        ->and($session->isRunning())->toBeFalse();
});

it('is idempotent: every close after the first returns the same terminal Result', function () {
    $session = sessionChild('echo');

    $first = $session->close();

    expect($session->close())->toBe($first)
        ->and($session->send('late'))->toBeFalse()
        ->and($session->receive())->toBeNull();
});

it('hands the child exactly the given environment and nothing inherited from the host', function () {
    putenv('POPCORN_SESSION_HOST_ONLY=leaked');

    try {
        $session = sessionChild('env', ['DECLARED' => 'yes']);
        $session->send('env?');
        $seen = json_decode((string) $session->receive(), true);
        $session->close();
    } finally {
        putenv('POPCORN_SESSION_HOST_ONLY');
    }

    // Exactly the given environment: equality, after only the closed, observed runtime additions for a PHP child on
    // this platform (RuntimeEnvAdditions; root 44be5842). Any other extra name, inherited or not, fails.
    expect($seen)->toBeArray()
        ->and(RuntimeEnvAdditions::withoutObserved($seen, ['DECLARED' => 'yes'], 'php'))->toBe(['DECLARED' => 'yes'])
        ->and($seen)->not->toHaveKey('POPCORN_SESSION_HOST_ONLY')
        ->and($seen)->not->toHaveKey('HOME');
});

it('reports a child that died by itself as NonZeroExit with its real code and stderr', function () {
    $session = sessionChild('fail');

    expect($session->receive())->toBeNull();

    $result = $session->close();

    expect($result->outcome)->toBe(Outcome::NonZeroExit)
        ->and($result->exitCode)->toBe(3)
        ->and($result->stderr)->toContain('child: refusing to start')
        ->and($result->error)->toContain('child: refusing to start')
        ->and($session->isRunning())->toBeFalse()
        ->and($session->send('anything'))->toBeFalse();
});

it('stops a child that ignores stdin EOF and records the signal as a host-ended Success', function () {
    $session = sessionChild('ignore');

    $result = $session->close();

    expect($result->outcome)->toBe(Outcome::Success)
        ->and($result->signal)->toBe(15)
        ->and($result->exitCode)->toBeNull()
        ->and($session->isRunning())->toBeFalse();
});

it('never wedges on a child that floods stderr', function () {
    $session = sessionChild('noisy');

    expect($session->send('after the flood'))->toBeTrue()
        ->and($session->receive())->toBe('echo:after the flood');

    $result = $session->close();

    expect($result->outcome)->toBe(Outcome::Success)
        ->and($result->stderrTruncated)->toBeTrue()
        ->and(strlen($result->stderr))->toBeLessThanOrEqual(16384);
});

it('reflects the sandboxed flag and the effective grant it was opened under', function () {
    $grant = new Grant(env: ['DECLARED' => 'yes'], net: Net::Scoped);
    $session = sessionChild('echo', ['DECLARED' => 'yes'], sandboxed: true, grant: $grant);

    expect($session->sandboxed())->toBeTrue()
        ->and($session->grant())->toBe($grant)
        ->and($session->close()->sandboxed)->toBeTrue();
});

it('refuses an empty argv without starting anything', function () {
    $session = ProcessSession::start([], [], false, Grant::none());

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->outcome)->toBe(Outcome::SubstrateUnavailable);
});

it('fails, never succeeds, when the binary cannot be executed', function () {
    $session = ProcessSession::start(['/nonexistent/popcorn-session-binary'], [], false, Grant::none());

    expect($session->close()->failed())->toBeTrue();
});

it('keeps a refused session distinguishable from a live one', function () {
    $refusal = Result::grantDenied(Rushing\Popcorn\Runner\GrantAxis::Env, 'LLM_KEY');
    $session = new RefusedSession($refusal);

    expect($session->refusal())->toBe($refusal)
        ->and($session->isRunning())->toBeFalse()
        ->and($session->pid())->toBeNull()
        ->and($session->sandboxed())->toBeFalse()
        ->and($session->send('x'))->toBeFalse()
        ->and($session->receive())->toBeNull()
        ->and($session->close())->toBe($refusal)
        ->and($session->close())->toBe($refusal);
});

it('will not build a refused session out of a successful Result', function () {
    new RefusedSession(Result::success('{}'));
})->throws(InvalidArgumentException::class);
