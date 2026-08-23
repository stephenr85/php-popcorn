<?php

use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;
use Rushing\Popcorn\Registries\Key;

/**
 * Drives the grammar half of the shared conformance corpus (registry-kernel ticket 05). The corpus is
 * JSON rather than inline datasets so the TS port (ticket 16) asserts the same cases instead of a second
 * reading of the prose — drift between the two runtimes is the risk this file exists to remove.
 *
 * The `routing` section is driven by `RegistryIndexTest`, and the {@see corpus()} loader moved to
 * `tests/Pest.php` when it acquired that second caller.
 */
dataset('grammar', fn () => array_map(
    fn (array $case) => [$case['input'], $case['segments']],
    corpus('grammar'),
));

dataset('equality', fn () => array_map(
    fn (array $case) => [$case['a'], $case['b'], $case['equal']],
    corpus('equality'),
));

dataset('legal', fn () => array_values(array_map(
    fn (array $case) => [$case['input'], $case['segments']],
    array_filter(corpus('grammar'), fn (array $case) => $case['segments'] !== null),
)));

dataset('hierarchy', fn () => array_map(fn (array $case) => [$case], corpus('hierarchy')));

it('parses exactly the keys the corpus says are legal', function (string $input, ?array $segments) {
    $parsed = Key::tryParse($input);

    if ($segments === null) {
        expect($parsed)->toBeNull();

        return;
    }

    expect($parsed)->not->toBeNull()
        ->and($parsed->segments())->toBe($segments)
        ->and((string) $parsed)->toBe($input);
})->with('grammar');

it('throws on an illegal key rather than repairing it', function (string $input, ?array $segments) {
    if ($segments !== null) {
        expect((string) Key::parse($input))->toBe($input);

        return;
    }

    expect(fn () => Key::parse($input))->toThrow(InvalidRegistryKey::class);
})->with('grammar');

it('round-trips every legal key through its own string form', function (string $input) {
    // Idempotence, which is trivially true only BECAUSE parsing never rewrites: the source string,
    // the printed form and a re-parse of the printed form are one value.
    expect((string) Key::parse((string) Key::parse($input)))->toBe($input);
})->with('legal');

it('defines equality on segments, not on the source string', function (string $a, string $b, bool $equal) {
    expect(Key::parse($a)->equals(Key::parse($b)))->toBe($equal)
        ->and(Key::parse($b)->equals(Key::parse($a)))->toBe($equal);
})->with('equality');

it('walks the hierarchy segment-wise', function (array $case) {
    $key = Key::parse($case['key']);

    if (array_key_exists('parent', $case)) {
        $parent = $key->parent();

        expect($parent === null ? null : (string) $parent)->toBe($case['parent']);
    }

    if (array_key_exists('isUnder', $case)) {
        expect($key->isUnder($case['under']))->toBe($case['isUnder']);
    }
})->with('hierarchy');

it('coerces the RegistryKey|string union without re-parsing a key', function () {
    $key = Key::parse('beam.realm');

    expect(Key::of($key))->toBe($key)
        ->and((string) Key::of('beam.realm'))->toBe('beam.realm');
});

it('derives a key from a class name only when asked explicitly', function () {
    expect((string) Key::fromClass(Key::class))->toBe('key')
        ->and((string) Key::fromClass('Splicewire\Beam\Realm\RealmRegistry'))->toBe('realm-registry')
        ->and((string) Key::fromClass('AcpFeedRegistry'))->toBe('acp-feed-registry');

    // The point of the explicit derivation: the raw class name is NOT a key.
    expect(Key::tryParse('RealmRegistry'))->toBeNull();
});

it('builds from segments', function () {
    expect((string) Key::fromSegments(['beam', 'realm', 'overlays']))->toBe('beam.realm.overlays')
        ->and(fn () => Key::fromSegments(['beam', 'Realm']))->toThrow(InvalidRegistryKey::class);
});
