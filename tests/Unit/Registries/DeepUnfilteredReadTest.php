<?php

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\CarriesDeclaration;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Nested;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * Two kernel seams that a docblock could not have held, registry-kernel tickets 45 and 59.
 *
 * **45** — `RegistryIndex::unfiltered()` used to unfilter *which registries you can see* and hand back
 * the live, still-gated singletons, so their ENTRIES stayed filtered. Three probes hit it and all three
 * read the result as agreement; ticket 45's acceptance asks for *"a test that would have caught the
 * one-level escape, not a docblock that describes it,"* because four predecessors in the same defect
 * class were documented too. That is the first half of this file.
 *
 * **59 B1** — `declarationOf()` type-tested `BasicRegistry`, so an archetype-**f** registry over an
 * external store (which holds no `BasicRegistry` by definition) could not declare inline and was forced
 * onto the class attribute that ticket 26 D2 forbids for that family. That is the second half.
 */

// ---------------------------------------------------------------------------------------------
// A registry with gated entries, and a port that composes one
// ---------------------------------------------------------------------------------------------

function gatedStore(string $root): BasicRegistry
{
    return (new BasicRegistry(new IsRegistry(
        root: $root,
        of: 'test entries',
        arity: RegistryArity::PickOne,
        onDuplicate: OnDuplicate::Supersede,
    )))
        ->register('open', 'visible to everyone')
        ->register('secret', 'gated', ability: 'read-secret');
}

function denyEverything(): Authorizer
{
    return new class implements Authorizer
    {
        public function allows(string $ability, RegistryKey $key): bool
        {
            return false;
        }
    };
}

/**
 * A composed registry — the estate's uniform shape: hold a `BasicRegistry` as a field, delegate, and
 * return the inner store from `unfiltered()`. Every migrated row in the fleet does exactly this.
 */
#[IsRegistry(
    root: 'demo.port',
    of: 'entries behind a port',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
)]
class DeepReadPortRegistry implements Gated, Registry
{
    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this)
            ->register('open', 'visible to everyone')
            ->register('secret', 'gated', ability: 'read-secret');
    }

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }
}

// ---------------------------------------------------------------------------------------------
// 45 — the escape reaches the entries, not just the registries
// ---------------------------------------------------------------------------------------------

it('unfilters the ENTRIES of the registries it hands back, not only which registries are visible', function () {
    $index = new RegistryIndex;
    $index->describe(gatedStore('demo.resources'));
    $index->authorizeWith(denyEverything());

    // The filtered read: the gated entry is absent, as it should be.
    expect($index->tryResolve('demo.resources')->keys())->toHaveCount(1);

    // The escape. Before ticket 45 this returned 1 as well — one level of escape, reported as success.
    expect($index->unfiltered()->tryResolve('demo.resources')->keys())->toHaveCount(2);
});

it('reaches through a composed port, which is what every hand-rolled workaround was doing', function () {
    $index = new RegistryIndex;
    $index->describe(new DeepReadPortRegistry);
    $index->authorizeWith(denyEverything());

    expect($index->tryResolve('demo.port')->keys())->toHaveCount(1)
        ->and($index->unfiltered()->tryResolve('demo.port')->keys())->toHaveCount(2);
});

it('deepens routeTo, ownerOf and matches, because they hand back stores too', function () {
    $index = new RegistryIndex;
    $index->describe(gatedStore('demo.resources'));
    $index->authorizeWith(denyEverything());

    $deep = $index->unfiltered();

    expect($deep->routeTo('demo.resources.secret')->keys())->toHaveCount(2)
        ->and($deep->ownerOf('demo.resources.secret')->keys())->toHaveCount(2);

    $matched = $deep->matches('demo.resources');
    expect($matched)->toHaveCount(1)
        ->and($matched[0]->keys())->toHaveCount(2);
});

it('crosses into an owned tree unfiltered, which is what the graph probe had to hand-roll', function () {
    $index = new RegistryIndex;
    $index->describe(gatedStore('demo.resources'));
    $index->authorizeWith(denyEverything());

    // `childrenAcross()` reaches the owning tree through `routeTo()`, so it inherits the depth for free.
    expect($index->childrenAcross('demo.resources'))->toHaveCount(1)
        ->and($index->unfiltered()->childrenAcross('demo.resources'))->toHaveCount(2);
});

it('does not mutate the live singleton — the deep view is clones all the way down', function () {
    $index = new RegistryIndex;
    $live = gatedStore('demo.resources');
    $index->describe($live);
    $index->authorizeWith(denyEverything());

    $index->unfiltered()->tryResolve('demo.resources')->keys();

    expect($live->keys())->toHaveCount(1)
        ->and($index->tryResolve('demo.resources')->keys())->toHaveCount(1);
});

it('is a no-op where no authorizer is installed, which is the estate today', function () {
    $index = new RegistryIndex;
    $index->describe(gatedStore('demo.resources'));

    expect($index->tryResolve('demo.resources')->keys())->toHaveCount(2)
        ->and($index->unfiltered()->tryResolve('demo.resources')->keys())->toHaveCount(2);
});

// ---------------------------------------------------------------------------------------------
// 59 B1 — a store with no BasicRegistry can still declare inline
// ---------------------------------------------------------------------------------------------

/**
 * The archetype-**f** shape in miniature: no array, no `BasicRegistry`, an "external store" (here a
 * closure over a plain map) and a declaration carried as a VALUE. It deliberately carries NO
 * class-level `#[IsRegistry]`, because ticket 26 D2's whole point is that one class in this family
 * plays several roles and a class attribute would assert one root across all of them.
 */
class ExternalStoreRegistry implements CarriesDeclaration, Registry
{
    /** @param array<string, string> $store */
    public function __construct(private IsRegistry $declaration, private array $store) {}

    public function declaration(): IsRegistry
    {
        return $this->declaration;
    }

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $this->store[(string) $key] = $entry;

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return array_key_exists((string) $key, $this->store);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store[(string) $key];
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store[(string) $key] ?? null;
    }

    public function matches(RegistryKey|string $key): array
    {
        return array_values($this->store);
    }

    public function keys(): array
    {
        return array_map(fn (string $key): RegistryKey => Rushing\Popcorn\Registries\Key::of($key), array_keys($this->store));
    }

    public function unfiltered(): Registry
    {
        return $this;
    }
}

it('asks a store that carries its declaration, so an external-store registry can declare INLINE', function () {
    $index = new RegistryIndex;

    $index->describe(new ExternalStoreRegistry(
        new IsRegistry(
            root: 'schemas.served',
            of: 'schema artifacts on disk',
            arity: RegistryArity::PickOne,
            onDuplicate: OnDuplicate::Supersede,
        ),
        ['schemas.served.grounding' => 'the grounding schema'],
    ));

    expect((string) $index->declarationAt('schemas.served')?->rootKey())->toBe('schemas.served')
        ->and($index->tryResolve('schemas.served'))->toBeInstanceOf(ExternalStoreRegistry::class);
});

it('lets two instances of ONE class declare two different roots — the rung distinction 26 D2 needs', function () {
    $index = new RegistryIndex;
    $declare = fn (string $root): IsRegistry => new IsRegistry(
        root: $root,
        of: 'schema artifacts',
        arity: RegistryArity::PickOne,
        onDuplicate: OnDuplicate::Supersede,
    );

    $index->describe(new ExternalStoreRegistry($declare('schemas.file'), []));
    $index->describe(new ExternalStoreRegistry($declare('schemas.db'), []));

    expect(array_map('strval', $index->unfiltered()->keys()))
        ->toContain('schemas.file')
        ->toContain('schemas.db');
});

it('still reads the class attribute where a store carries no instance declaration', function () {
    $index = new RegistryIndex;
    $index->describe(new DeepReadPortRegistry);

    expect((string) $index->declarationAt('demo.port')?->rootKey())->toBe('demo.port');
});

it('keeps BasicRegistry on the same door it always used', function () {
    expect(gatedStore('demo.resources'))->toBeInstanceOf(CarriesDeclaration::class)
        ->and(gatedStore('demo.resources')->declaration()->root)->toBe('demo.resources');
});

it('leaves Nested branches alone — they are the same entry list, not a second escape', function () {
    $store = gatedStore('demo.resources');

    expect($store)->toBeInstanceOf(Nested::class)
        ->and($store->children('demo.resources'))->toHaveCount(2)
        ->and($store->unfiltered()->children('demo.resources'))->toHaveCount(2);
});

// ---------------------------------------------------------------------------------------------
// 48 — the registrant read: `by` is written on every entry and was read by nothing
// ---------------------------------------------------------------------------------------------

it('reads back the registrant of a live entry, which no contract method could before', function () {
    $store = gatedStore('demo.resources')->register('third', 'c', by: 'acme/package');

    expect($store->registrantOf('demo.resources.third'))->toBe('acme/package')
        ->and($store->registrantOf('demo.resources.open'))->toBeNull()      // legal, and the majority case today
        ->and($store->registrantOf('demo.resources.nope'))->toBeNull();
});

it('selects every key a registrant wrote — forgetBy()\'s read twin, on the same exact match', function () {
    $store = gatedStore('demo.resources')
        ->register('a', 1, by: 'tower/conduit-hydrator')
        ->register('b', 2, by: 'tower/conduit-hydrator')
        ->register('c', 3, by: 'someone-else');

    expect(array_map('strval', $store->keysBy('tower/conduit-hydrator')))
        ->toBe(['demo.resources.a', 'demo.resources.b']);

    // The twin agrees with the destructive half, which is the whole reason it matches exactly.
    $store->forgetBy('tower/conduit-hydrator');

    expect($store->keysBy('tower/conduit-hydrator'))->toBe([]);
});

it('filters both registrant reads, because an unfiltered one is an existence oracle', function () {
    $store = gatedStore('demo.resources')->register('vault', 'v', by: 'acme/package', ability: 'read-vault');
    $store->authorizeWith(denyEverything());

    expect($store->registrantOf('demo.resources.vault'))->toBeNull()
        ->and($store->keysBy('acme/package'))->toBe([])
        ->and($store->unfiltered()->registrantOf('demo.resources.vault'))->toBe('acme/package')
        ->and($store->unfiltered()->keysBy('acme/package'))->toHaveCount(1);
});

it('answers the FIRST registrant at an Admit key rather than throwing, unlike resolve()', function () {
    $store = (new BasicRegistry(new IsRegistry(
        root: 'demo.admit',
        of: 'test entries',
        arity: RegistryArity::RunAll,
        onDuplicate: OnDuplicate::Admit,
    )))
        ->register('hook', 'first', by: 'package-a')
        ->register('hook', 'second', by: 'package-b');

    // "which entry" has no answer and throws; "who put something here" does, and it is who made the
    // key exist.
    expect($store->registrantOf('demo.admit.hook'))->toBe('package-a');
});

it('gives the index a total registrant vocabulary, unlike the entry-level population', function () {
    $index = new RegistryIndex;
    $index->describe(gatedStore('demo.resources'), by: 'acme/demo-package');

    expect($index->registrantOf('demo.resources'))->toBe('acme/demo-package')
        ->and(array_map('strval', $index->keysBy('acme/demo-package')))->toBe(['demo.resources']);
});
