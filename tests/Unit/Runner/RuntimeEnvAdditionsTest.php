<?php

use Rushing\Popcorn\Runner\RuntimeEnvAdditions;

/**
 * The closed list of observed post-exec runtime additions (root 44be5842). It is a ruling, so its contents are pinned
 * here: a new entry needs a new ruling AND a change to this test.
 */
it('holds exactly one runtime, one platform and one name', function () {
    expect(RuntimeEnvAdditions::OBSERVED)->toBe(['php' => ['Darwin' => ['__CF_USER_TEXT_ENCODING']]]);
});

it('allows the name only for a PHP child on Darwin', function () {
    expect(RuntimeEnvAdditions::for('php', 'Darwin'))->toBe(['__CF_USER_TEXT_ENCODING'])
        ->and(RuntimeEnvAdditions::for('php', 'Linux'))->toBe([])
        ->and(RuntimeEnvAdditions::for('node', 'Darwin'))->toBe([]);
});

it('removes only the listed name, so any other extra still breaks exact equality', function () {
    $observed = ['LLM_KEY' => 'k', '__CF_USER_TEXT_ENCODING' => 'v', 'HOME' => '/h'];

    expect(RuntimeEnvAdditions::withoutObserved($observed, ['LLM_KEY' => 'k'], 'php', 'Darwin'))
        ->toBe(['LLM_KEY' => 'k', 'HOME' => '/h'])
        ->not->toBe(['LLM_KEY' => 'k']);
});

it('never removes anything off the listed runtime and platform', function () {
    $observed = ['LLM_KEY' => 'k', '__CF_USER_TEXT_ENCODING' => 'v'];

    expect(RuntimeEnvAdditions::withoutObserved($observed, ['LLM_KEY' => 'k'], 'php', 'Linux'))->toBe($observed)
        ->and(RuntimeEnvAdditions::withoutObserved($observed, ['LLM_KEY' => 'k'], 'node', 'Darwin'))->toBe($observed);
});

it('keeps a listed name the Grant itself delivered', function () {
    $granted = ['__CF_USER_TEXT_ENCODING' => 'granted'];

    expect(RuntimeEnvAdditions::withoutObserved($granted, $granted, 'php', 'Darwin'))->toBe($granted);
});
