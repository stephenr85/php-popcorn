<?php

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\Exceptions\UnbakedRegistryIndex;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\LazyRegistry;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\NotARegistry;

/**
 * The BAKED, lazily-resolved membership path — registry-kernel ticket 73 phase B, on D2 and D3.
 *
 * Every test here is written against a claim that a happy-path test cannot see. Laziness is invisible
 * unless you count constructions; "absent is loud" is invisible unless you distinguish it from empty;
 * and the authorizer push is invisible unless the fixture records being pushed.
 */
beforeEach(function () {
    LazyRegistry::$constructions = 0;
});

it('constructs NOTHING when a root is baked', function () {
    $index = new RegistryIndex;
    $index->describeLazily('lazy.demo', LazyRegistry::class, by: 'bake');

    // The entire point of D2: boot pays a string per registry, not a construction. Counting is the only
    // instrument that can tell this apart from eager describing — both leave a routable index.
    expect(LazyRegistry::$constructions)->toBe(0)
        ->and($index->pending())->toBe(['lazy.demo' => LazyRegistry::class]);
});

it('resolves the ONE root that routing lands on, and only that one', function () {
    $index = new RegistryIndex;
    $index->describeLazily('lazy.demo', LazyRegistry::class, by: 'bake');
    $index->describeLazily('lazy.other', LazyRegistry::class, by: 'bake');

    $index->routeTo('lazy.demo.one');

    expect(LazyRegistry::$constructions)->toBe(1)
        ->and(array_keys($index->pending()))->toBe(['lazy.other']);
});

it('routes to a baked root before it has ever been resolved', function () {
    $index = new RegistryIndex;
    $index->describeLazily('lazy.demo', LazyRegistry::class, by: 'bake');

    expect($index->routeTo('lazy.demo.one'))->toBeInstanceOf(LazyRegistry::class)
        ->and($index->routeTo('lazy.demo.one')->resolve('one'))->toBe('first');
});

it('resolves through the caller-supplied resolver, so the HOST binding is what answers', function () {
    // Phase A's A3 in miniature: four tower registries are bound by the HOST behind configuring
    // closures, so an eager `describe($app->make(...))` from the owning package fabricates a fresh
    // unconfigured singleton wherever nothing binds one. Resolving at READ time through the container
    // is what makes the host's own object the one that answers.
    $configured = new LazyRegistry;
    $configured->register('host-only', 'configured by the host', by: 'host');

    $index = new RegistryIndex;
    $index->resolveLazilyWith(fn (string $class) => $configured);
    $index->describeLazily('lazy.demo', LazyRegistry::class, by: 'bake');

    expect($index->routeTo('lazy.demo.host-only'))->toBe($configured)
        ->and($index->routeTo('lazy.demo.host-only')->resolve('host-only'))->toBe('configured by the host');
});

it('pushes the Gated authorizer AT RESOLUTION, not at bake', function () {
    $authorizer = new class implements Authorizer
    {
        public function allows(string $ability, mixed $entry): bool
        {
            return true;
        }
    };

    $index = new RegistryIndex;
    $index->authorizeWith($authorizer);
    $index->describeLazily('lazy.demo', LazyRegistry::class, by: 'bake');

    // Nothing exists yet, so nothing can have been pushed — which is precisely the window in which the
    // push could be dropped silently and leave an unauthorized registry in the index.
    expect(LazyRegistry::$constructions)->toBe(0);

    $resolved = $index->routeTo('lazy.demo.one');

    expect($resolved->authorizerWasPushed)->toBeTrue()
        ->and($resolved->seen)->toBe($authorizer);
});

it('keeps the bake registrant, so provenance survives hydration', function () {
    $index = new RegistryIndex;
    $index->describeLazily('lazy.demo', LazyRegistry::class, by: 'the-bake');

    $index->routeTo('lazy.demo.one');

    expect(array_map('strval', $index->keysBy('the-bake')))->toBe(['lazy.demo']);
});

it('hydrates everything for an enumeration, because enumeration cannot be lazy', function () {
    $index = new RegistryIndex;
    $index->describeLazily('lazy.demo', LazyRegistry::class, by: 'bake');

    expect(array_map('strval', $index->keys()))->toContain('lazy.demo')
        ->and(LazyRegistry::$constructions)->toBe(1)
        ->and($index->pending())->toBe([]);
});

it('refuses a baked class that does not implement the contract, naming the gate that owns it', function () {
    $index = new RegistryIndex;
    $index->describeLazily('lazy.broken', NotARegistry::class, by: 'bake');

    expect(fn () => $index->routeTo('lazy.broken.anything'))
        ->toThrow(InvalidArgumentException::class, 'does not implement');
});

it('THROWS on a membership read when the baked list is absent, rather than reporting an empty estate', function () {
    // The sharpest risk in the whole design: after the cutover there is no hand-written describe to fall
    // back on, so a missing artifact would otherwise mean every routeTo() returns null, popcorn:registries
    // shows nothing, and the Gated authorizer is never installed — with nothing anywhere saying so.
    $index = (new RegistryIndex)->markUnbaked('no artifact');

    expect($index->isUnbaked())->toBeTrue()
        ->and(fn () => $index->routeTo('anything'))->toThrow(UnbakedRegistryIndex::class)
        ->and(fn () => $index->keys())->toThrow(UnbakedRegistryIndex::class)
        ->and(fn () => $index->has('anything'))->toThrow(UnbakedRegistryIndex::class)
        ->and(fn () => $index->unfiltered())->toThrow(UnbakedRegistryIndex::class)
        ->and(fn () => $index->ownerOf('anything'))->toThrow(UnbakedRegistryIndex::class);
});

it('is still constructible and describable while unbaked, or the bake command could never run', function () {
    // A real bootstrap cycle, not a hypothesis: the command that writes the artifact is an artisan
    // command, and artisan boots the application.
    $index = (new RegistryIndex)->markUnbaked('no artifact');

    expect($index->describeLazily('lazy.demo', LazyRegistry::class))->toBe($index);
});

it('distinguishes a MISSING list from a host that genuinely declares nothing', function () {
    // An artifact that exists and lists nothing is legal and quiet; only a missing one is loud. Reading
    // both as an empty array is the collapse this whole ruling exists to prevent.
    $baked = new RegistryIndex;

    expect($baked->isUnbaked())->toBeFalse()
        ->and(array_map('strval', $baked->keys()))->toBe([''])
        ->and($baked->routeTo('nothing.here'))->toBeNull();
});

it('stops being blind the moment something is described by hand', function () {
    // The transition rule, and it is what makes the cutover survivable in either order: "unbaked" means
    // NO membership source at all. A host still running hand-written describes has one, whatever the
    // artifact's state, so it behaves exactly as it always did.
    $index = (new RegistryIndex)->markUnbaked('no artifact');

    $index->describe(new LazyRegistry);

    expect($index->isUnbaked())->toBeFalse()
        ->and($index->routeTo('lazy.demo.one')->resolve('one'))->toBe('first');
});

it('does not count its own self-hosting describe as a membership source', function () {
    // The constructor describes the index into itself. Counting that would make every index look
    // supplied and make the blindness unreachable — the check would exist and never fire.
    $index = (new RegistryIndex)->markUnbaked('no artifact');

    expect($index->isUnbaked())->toBeTrue();
});

it('lets a hand describe supersede a pending bake for the same root, without resolving it twice', function () {
    $index = new RegistryIndex;
    $index->describeLazily('lazy.demo', LazyRegistry::class, by: 'bake');

    $byHand = new LazyRegistry;
    $index->describe($byHand);

    // The hand-described object is the one that answers, and the pending entry is gone rather than
    // waiting to overwrite it on the next read — which is what keeps the transitional estate (bake
    // present AND hand describes still in place) from describing every root twice.
    expect($index->pending())->toBe([])
        ->and($index->routeTo('lazy.demo.one'))->toBe($byHand)
        ->and(LazyRegistry::$constructions)->toBe(1);
});

it('RECORDS a baked root it cannot resolve here, instead of taking the whole index down', function () {
    // The bake reads declarations off the filesystem, and a declaration's container binding often lives
    // in a different package's provider — measured as the normal case, not the exception. An environment
    // that composes the declaring package without the binding one meets a class it cannot build, and
    // that is a COMPOSITION fact, which this estate's rule says must not be fatal.
    $index = new RegistryIndex;
    $index->resolveLazilyWith(function (string $class) {
        throw new RuntimeException('Unresolvable dependency resolving [$roots]');
    });
    $index->describeLazily('lazy.demo', LazyRegistry::class, by: 'bake');

    // An honest miss, not an exception, and the reason is readable rather than swallowed.
    expect($index->routeTo('lazy.demo.one'))->toBeNull()
        ->and($index->unresolvable())->toHaveKey('lazy.demo')
        ->and($index->unresolvable()['lazy.demo'])->toContain('Unresolvable dependency');
});

it('still refuses a baked class that is simply not a Registry, because that is the AUTHOR\'s error', function () {
    // The two failures are different in kind and must not be collapsed. "I cannot build this here" is a
    // fact about the host; "this class does not implement the contract" is a fact the declaration's
    // author could have gotten right without knowing any host — so the first records and the second
    // throws, which is the same line this ticket drew for shadowing in §1.
    $index = new RegistryIndex;
    $index->describeLazily('lazy.broken', NotARegistry::class, by: 'bake');

    expect(fn () => $index->routeTo('lazy.broken.anything'))
        ->toThrow(InvalidArgumentException::class, 'does not implement')
        ->and($index->unresolvable())->toBe([]);
});
