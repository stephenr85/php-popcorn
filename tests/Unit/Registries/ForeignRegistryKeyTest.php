<?php

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\BranchKey;
use Rushing\Popcorn\Registries\Exceptions\MissReason;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\NamespaceUriKey;

/**
 * `RegistryKey` is a SEAM, not a fixed grammar (registry-kernel ticket 05 as amended, found by
 * ticket 11). A consumer's own key type — json-ns's namespace URI, an app's `ResourceKey` — IS a
 * registry key, with its own syntax and its own rendering.
 *
 * These are the tests that were missing when ticket 23 landed the seam its own reference
 * implementation could not carry. `BasicRegistry` flattened every key to `(string) Key::of($key)` at
 * the door and recovered structure by re-parsing it with `Key::parse()`, so on a foreign key
 * `matches()`, `keys()`, `children()` and a MISSING `resolve()` all threw `InvalidRegistryKey` — and,
 * worse, `has()` and a hitting `resolve()` silently "worked" by string equality, which is exactly what
 * `RegistryKey::equals()` ("equality is defined on segments, NEVER on the source string") forbids.
 *
 * The rule they pin: the kernel COMPARES and JOINS segments and never parses one.
 */
function uriRegistry(OnDuplicate $onDuplicate = OnDuplicate::Supersede): BasicRegistry
{
    return new BasicRegistry(new IsRegistry(
        root: 'jsonns.namespaces',
        of: 'namespace handlers',
        arity: RegistryArity::PickOne,
        onDuplicate: $onDuplicate,
        optionality: Optionality::Optional,
    ));
}

it('registers and resolves a key whose rendering Key::parse would reject outright', function () {
    $key = NamespaceUriKey::of('https://schemastud.dev/ns/grounding/2');

    $registry = uriRegistry()->register($key, 'pinned-handler', by: 'schemastud/laravel-json-ns');

    expect($registry->has($key))->toBeTrue()
        ->and($registry->resolve($key))->toBe('pinned-handler');
});

it('misses on a foreign key with a RegistryMiss, never an InvalidRegistryKey', function () {
    // The regression that mattered most: resolve()'s branch check re-parsed on the way to reporting
    // absence, so a MISS threw a parse error and tryResolve() — which only catches RegistryMiss —
    // propagated it, breaking ticket 23's chartered null-on-miss/throw-on-ambiguity split.
    $registry = uriRegistry();
    $absent = NamespaceUriKey::of('https://schemastud.dev/ns/nothing');

    expect(fn () => $registry->resolve($absent))
        ->toThrow(RegistryMiss::class)
        ->and($registry->tryResolve($absent))->toBeNull();

    try {
        $registry->resolve($absent);
    } catch (RegistryMiss $miss) {
        expect($miss->reason)->toBe(MissReason::Absent);
    }
});

it('defines equality on segments and never on the rendered string', function () {
    $registry = uriRegistry(OnDuplicate::Reject);

    $registry->register(NamespaceUriKey::of('https://schemastud.dev/ns/grounding/2'), 'first', by: 'a');

    // A DIFFERENT rendering with the same segments is the SAME key, so Reject must fire. Under string
    // identity these were two entries.
    expect(fn () => $registry->register(
        NamespaceUriKey::of('https://schemastud.dev/ns/grounding/2'), 'second', by: 'b',
    ))->toThrow(Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey::class);
});

it('walks the tree segment-wise, so a pin is a child of its stem', function () {
    $registry = uriRegistry()
        ->register(NamespaceUriKey::of('https://schemastud.dev/ns/grounding/1'), 'v1', by: 'a')
        ->register(NamespaceUriKey::of('https://schemastud.dev/ns/grounding/2'), 'v2', by: 'a')
        ->register(NamespaceUriKey::of('https://schemastud.dev/ns/other/1'), 'other', by: 'a');

    $stem = new BranchKey(['https://schemastud.dev/ns/grounding']);

    expect($registry->matches($stem))->toBe(['v1', 'v2'])
        ->and(array_map('strval', $registry->children($stem)))
        ->toBe(['https://schemastud.dev/ns/grounding/1', 'https://schemastud.dev/ns/grounding/2']);
});

it('reports the registrant own key object, keeping the foreign rendering intact', function () {
    $registry = uriRegistry()
        ->register(NamespaceUriKey::of('https://schemastud.dev/ns/grounding/2'), 'v2', by: 'a');

    [$key] = $registry->keys();

    expect($key)->toBeInstanceOf(NamespaceUriKey::class)
        ->and((string) $key)->toBe('https://schemastud.dev/ns/grounding/2')
        ->and($key->segments())->toBe(['https://schemastud.dev/ns/grounding', '2']);
});

it('forgets and supersedes a foreign key by its segments', function () {
    $key = NamespaceUriKey::of('https://schemastud.dev/ns/grounding/2');

    $registry = uriRegistry()
        ->register($key, 'local-default', by: 'schemastud/laravel-json-ns')
        ->register($key, 'tenant-webhook', by: 'tenant-x');

    expect($registry->resolve($key))->toBe('tenant-webhook')
        ->and($registry->superseded($key))->toHaveCount(1);

    // The record holds the KEY, not its rendering (ticket 16). `Superseded::$key` was a string until
    // that ticket, which was invisible: `Key` round-trips through `(string)`, so every test passed and
    // supersession history was lossy for exactly the keys that cannot be reconstructed from a rendering.
    // The same trap ticket 11 found in `BasicRegistry`, in a place a green suite could not report.
    expect($registry->superseded($key)[0]->key)->toBeInstanceOf(NamespaceUriKey::class)
        ->and($registry->superseded($key)[0]->key->equals($key))->toBeTrue()
        ->and($registry->superseded($key)[0]->entry)->toBe('local-default');

    $registry->forget($key);

    expect($registry->has($key))->toBeFalse()
        ->and($registry->superseded($key))->toBe([]);
});

it('does not collide two keyspaces whose renderings differ but whose segments would join alike', function () {
    // Segments are opaque, so joining them on `.` or `/` to make a bucket could fuse distinct keys.
    $registry = uriRegistry();

    $registry->register(new BranchKey(['a.b', 'c']), 'first', by: 'x');
    $registry->register(new BranchKey(['a', 'b.c']), 'second', by: 'y');

    expect($registry->resolve(new BranchKey(['a.b', 'c'])))->toBe('first')
        ->and($registry->resolve(new BranchKey(['a', 'b.c'])))->toBe('second');
});
