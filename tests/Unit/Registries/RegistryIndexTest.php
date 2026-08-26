<?php

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Exceptions\AmbiguousRegistryMatch;
use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\Exceptions\ShadowedRegistryKey;
use Rushing\Popcorn\Registries\Exceptions\UnregisteredRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\NamespaceUriKey;

/**
 * `RegistryIndex` and the root-stamping door (registry-kernel ticket 20). These assert the decisions
 * that are cheapest to erode later: that the index conforms to its own contract rather than carrying an
 * exemption from it, that routing cannot silently succeed, that stamping makes the root unspellable at a
 * call site, and that a foreign-keyed registry stays out of the global keyspace instead of being
 * quietly coerced into it.
 */
function store(string $root, OnDuplicate $onDuplicate = OnDuplicate::Supersede): BasicRegistry
{
    return new BasicRegistry(new IsRegistry(
        root: $root,
        of: 'test entries',
        arity: RegistryArity::PickOne,
        onDuplicate: $onDuplicate,
    ));
}

// ---------------------------------------------------------------------------------------------
// Relative in, absolute out
// ---------------------------------------------------------------------------------------------

it('stamps the declared root onto a relative key, and stores it absolute', function () {
    $registry = store('beam.particle.resources')->register('invoices', 'the invoices resource');

    expect(array_map('strval', $registry->keys()))->toBe(['beam.particle.resources.invoices'])
        ->and($registry->resolve('invoices'))->toBe('the invoices resource')
        ->and($registry->resolve('beam.particle.resources.invoices'))->toBe('the invoices resource');
});

it('leaves a key already under the root alone, so enumerate-then-resolve round-trips', function () {
    $registry = store('beam.particle.resources')
        ->register('beam.particle.resources.invoices', 'a')
        ->register('orders', 'b');

    foreach ($registry->keys() as $key) {
        expect($registry->resolve($key))->not->toBeNull();
    }

    expect(array_map('strval', $registry->keys()))
        ->toBe(['beam.particle.resources.invoices', 'beam.particle.resources.orders']);
});

it('treats a key EQUAL to the root as absolute, not as a child of that name', function () {
    $registry = store('beam.realm')->register('beam.realm', 'the branch entry');

    expect(array_map('strval', $registry->keys()))->toBe(['beam.realm'])
        ->and($registry->resolve('beam.realm'))->toBe('the branch entry');
});

it('makes the root unspellable: a key outside it is relative, and is stamped', function () {
    // The corollary of stamping, asserted so nobody "fixes" it later: there is no way to distinguish
    // "I meant this relatively" from "I typo'd an absolute key", and inventing one would put a parser
    // back in the registry.
    $registry = store('beam.resources')->register('beam.realms.article', 'stamped anyway');

    expect(array_map('strval', $registry->keys()))->toBe(['beam.resources.beam.realms.article']);
});

it('does not stamp a foreign key, keeping that registry out of the global keyspace', function () {
    $registry = store('schemastud.json-ns.namespaces');
    $key = NamespaceUriKey::of('https://schemastud.dev/grounding/2');

    $registry->register($key, 'the grounding namespace');

    expect(array_map('strval', $registry->keys()))->toBe(['https://schemastud.dev/grounding/2'])
        ->and($registry->resolve($key))->toBe('the grounding namespace');
});

// ---------------------------------------------------------------------------------------------
// The index under its own contract
// ---------------------------------------------------------------------------------------------

it('self-hosts under the zero-segment root, so Required is true by construction', function () {
    $index = new RegistryIndex;

    expect($index)->toBeInstanceOf(Registry::class)
        ->and(IsRegistry::of($index)->root)->toBe('')
        ->and(IsRegistry::of($index)->optionality)->toBe(Optionality::Required)
        ->and($index->resolve(Key::root()))->toBe($index)
        ->and($index->owner(Key::root()))->toBe($index);
});

it('takes a live registry and never constructs one', function () {
    $index = new RegistryIndex;
    $resources = store('beam.particle.resources')->register('invoices', 'the invoices resource');

    $index->describe($resources);

    expect(array_map('strval', $index->keys()))->toBe(['', 'beam.particle.resources'])
        ->and($index->resolve('beam.particle.resources'))->toBe($resources);
});

it('returns the OWNER where one was named, and the store where none was', function () {
    $index = new RegistryIndex;
    $store = store('beam.particle.resources');
    $owner = new stdClass;

    $index->describe($store, $owner);

    expect($index->owner('beam.particle.resources'))->toBe($owner)
        ->and($index->routeTo('beam.particle.resources.invoices'))->toBe($store);
});

it('reads a described registry\'s declaration off the LIVE registry, not off its owner\'s class', function () {
    $index = new RegistryIndex;
    // The owner is a bare stdClass — it carries no `#[IsRegistry]` and could not. The declaration exists
    // only as the instance field the store was constructed with, which is the sanctioned way to root a
    // registry at a value computed at boot (ticket 26 D2). A reader that reflects the owner sees nothing
    // and concludes the registry is undeclared; that conclusion was a live defect in beam's gate (49).
    $index->describe(store('beam.particle.resources'), new stdClass);

    expect($index->declarationAt('beam.particle.resources')?->root)->toBe('beam.particle.resources')
        ->and($index->declarationAt('beam.particle.resources')?->arity)->toBe([RegistryArity::PickOne])
        ->and($index->declarationAt('nothing.described.here'))->toBeNull();
});

it('refuses a second registry claiming one root, rather than recording a supersession', function () {
    $index = new RegistryIndex;
    $index->describe(store('beam.particle.resources'));

    expect(fn () => $index->describe(store('beam.particle.resources')))
        ->toThrow(DuplicateRegistryKey::class);
});

// ---------------------------------------------------------------------------------------------
// Longest-prefix routing
// ---------------------------------------------------------------------------------------------

it('routes to the LONGEST matching root, segment-wise', function () {
    $index = new RegistryIndex;
    $realm = store('beam.realm');
    $overlays = store('beam.realm.overlays');
    $realms = store('beam.realms');

    $index->describe($realm)->describe($overlays)->describe($realms);

    expect($index->routeTo('beam.realm.overlays.article'))->toBe($overlays)
        ->and($index->routeTo('beam.realm.tenant'))->toBe($realm)
        // `beam.realms` is not under `beam.realm`, however much the strings suggest otherwise.
        ->and($index->routeTo('beam.realms.article'))->toBe($realms);
});

it('routes an exact root hit to that registry, which then answers by its own rules', function () {
    $index = new RegistryIndex;
    $resources = store('beam.particle.resources')->register('invoices', 'a')->register('orders', 'b');

    $index->describe($resources);

    // The registry is what a root-exact key routes to — but `pop()` then asks it for an ENTRY, and a
    // key naming a branch is ambiguous rather than absent (ticket 23). Reaching the registry OBJECT is
    // `owner()`'s job, not a polymorphic return from a read.
    expect($index->routeTo('beam.particle.resources'))->toBe($resources)
        ->and(fn () => $resources->resolve('beam.particle.resources'))
        ->toThrow(AmbiguousRegistryMatch::class);
});

it('never lets its own empty root swallow an unroutable key', function () {
    $index = new RegistryIndex;
    $index->describe(store('beam.particle.resources'));

    expect($index->routeTo('nothing.claims.this'))->toBeNull()
        ->and(fn () => $index->ownerOf('nothing.claims.this'))
        ->toThrow(UnregisteredRegistry::class)
        // …but an exact hit on the root is a real declared root, not a fallback.
        ->and($index->routeTo(Key::root()))->toBe($index);
});

it('names the declared roots when nothing claims a key', function () {
    $index = new RegistryIndex;
    $index->describe(store('beam.particle.resources'));

    try {
        $index->ownerOf('graph.stores.neo4j');
    } catch (UnregisteredRegistry $unregistered) {
        expect($unregistered->getMessage())
            ->toContain('`graph.stores.neo4j`')
            ->toContain('`beam.particle.resources`')
            // A SIBLING of RegistryMiss, not a subclass: "no such registry" and "no such entry" are
            // different operator errors, and catching one must not catch the other (ticket 13 D1).
            ->and($unregistered)->not->toBeInstanceOf(RegistryMiss::class);

        return;
    }

    $this->fail('ownerOf() did not throw on an unclaimed key.');
});

// ---------------------------------------------------------------------------------------------
// The authorizer, pushed on both edges
// ---------------------------------------------------------------------------------------------

function denyAll(): Authorizer
{
    return new class implements Authorizer
    {
        public function allows(string $ability, RegistryKey $key): bool
        {
            return false;
        }
    };
}

it('pushes the authorizer into registries described BEFORE it was installed', function () {
    $index = new RegistryIndex;
    $resources = store('beam.particle.resources')
        ->register('invoices', 'a')
        ->register('payroll', 'b', ability: 'view-payroll');

    $index->describe($resources);
    $index->authorizeWith(denyAll());

    expect($resources->has('payroll'))->toBeFalse()
        ->and($resources->matches('beam.particle.resources'))->toBe(['a']);
});

it('pushes the authorizer into registries described AFTER it was installed', function () {
    $index = new RegistryIndex;
    $index->authorizeWith(denyAll());

    $resources = store('beam.particle.resources')
        ->register('invoices', 'a')
        ->register('payroll', 'b', ability: 'view-payroll');

    $index->describe($resources);

    expect($resources->has('payroll'))->toBeFalse();
});

it('reaches a registry held directly, because the index holds the same instance', function () {
    // The whole reason "one authorizer on the index" is a guarantee rather than a convention: a
    // consumer injecting the registry from the container gets the object the index already stamped.
    $index = new RegistryIndex;
    $resources = store('beam.particle.resources')->register('payroll', 'b', ability: 'view-payroll');

    $index->describe($resources);
    $index->authorizeWith(denyAll());

    $injected = $resources;

    expect($injected->has('payroll'))->toBeFalse()
        ->and($injected->unfiltered()->has('payroll'))->toBeTrue();
});

it('declares the capability on the type, so a registry that cannot receive one says so', function () {
    expect(store('beam.resources'))->toBeInstanceOf(Gated::class)
        ->and(new RegistryIndex)->toBeInstanceOf(Gated::class);
});

// ---------------------------------------------------------------------------------------------
// Forgettable (registry-kernel ticket 41 D8)
//
// The index is bound singleton() while the front door is scoped(), and tenant switches happen
// mid-request — so the standing rule is that a registry is described once and its ENTRIES vary
// per tenant (41 D7). These assert the safety valve for the route that does not follow it, and
// the one removal that must never succeed.
// ---------------------------------------------------------------------------------------------

it('forgets a described registry by its root, and the owner record goes with it', function () {
    $index = new RegistryIndex;
    $owner = new stdClass;

    $index->describe(store('tenant.scratch'), $owner);

    expect($index->owner('tenant.scratch'))->toBe($owner)
        ->and($index->routeTo('tenant.scratch.thing'))->not->toBeNull();

    $index->forget('tenant.scratch');

    expect($index->owner('tenant.scratch'))->toBeNull()
        ->and($index->routeTo('tenant.scratch.thing'))->toBeNull();
});

it('lets a forgotten root be described again, which is the whole point of the teardown', function () {
    $index = new RegistryIndex;

    $index->describe(store('tenant.scratch'));
    $index->forget('tenant.scratch');

    expect(fn () => $index->describe(store('tenant.scratch')))->not->toThrow(DuplicateRegistryKey::class);
});

it('refuses to forget its own zero-segment root', function () {
    $index = new RegistryIndex;

    expect(fn () => $index->forget(Key::root()))->toThrow(InvalidArgumentException::class);
    expect($index->resolve(Key::root()))->toBe($index);
});

it('unwinds every registry described under one registrant selector', function () {
    $index = new RegistryIndex;

    $index->describe(store('tenant.one'), by: 'tenant:42');
    $index->describe(store('tenant.two'), by: 'tenant:42');
    $index->describe(store('shared.thing'), by: 'tenant:99');

    $index->forgetBy('tenant:42');

    expect($index->routeTo('tenant.one.x'))->toBeNull()
        ->and($index->routeTo('tenant.two.x'))->toBeNull()
        ->and($index->routeTo('shared.thing.x'))->not->toBeNull();
});

it('defaults the registrant to the owner class, so a package unwind stays provider-scale', function () {
    $index = new RegistryIndex;
    $owner = new stdClass;

    $index->describe(store('pkg.thing'), $owner);
    $index->forgetBy(stdClass::class);

    expect($index->routeTo('pkg.thing.x'))->toBeNull();
});

it('survives a bulk unwind naming its own class, rather than un-hosting itself', function () {
    $index = new RegistryIndex;

    $index->forgetBy(RegistryIndex::class);

    expect($index->resolve(Key::root()))->toBe($index);
});

// ---------------------------------------------------------------------------------------------
// Longest-prefix routing, from the shared conformance corpus
// ---------------------------------------------------------------------------------------------

/**
 * The corpus's `routing` section (registry-kernel ticket 16, paying off ticket 21's unmet acceptance
 * item). The cases above assert routing inline and stay as they are; these assert the SAME walk from
 * data a second runtime can read.
 *
 * Routing is the reason this section had to exist. Everything else the corpus covers ports to something
 * a porter cannot get subtly wrong — the grammar is a regex and a `split('.')`, the three surviving
 * enums are backed strings, and `parent()`/`isUnder()` are array slices. Longest-prefix routing is an
 * algorithm, with two ways to get it wrong that both look right: matching characters instead of
 * segments, and letting the index's zero-segment root serve as a fallback so that nothing ever fails to
 * route. Both are pinned below.
 */
dataset('routing', fn () => array_map(
    fn (array $case) => [$case],
    array_values(array_filter(corpus('routing'), fn (array $case) => ! isset($case['$comment']))),
));

it('routes a key to the registry whose declared root is its longest prefix', function (array $case) {
    $index = new RegistryIndex;

    foreach ($case['roots'] as $root) {
        $index->describe(store($root));
    }

    // A null key in the corpus is the zero-segment root, which has no string spelling — see the case's
    // own `why`. Everything else routes by the string a caller would actually write.
    $routed = $index->routeTo($case['key'] ?? Key::root());
    $why = $case['why'] ?? '';

    if ($case['owner'] === null) {
        expect($routed)->toBeNull($why);

        return;
    }

    if ($case['owner'] === '') {
        expect($routed)->toBe($index, $why);

        return;
    }

    expect($routed)->toBeInstanceOf(BasicRegistry::class, $why)
        ->and($routed->root())->toBe($case['owner'], $why);
})->with('routing');

// ---------------------------------------------------------------------------------------------
// Interleaved roots are legal; shadowing is not (ticket 26)
// ---------------------------------------------------------------------------------------------

it('admits a root nested inside another, because longest-prefix routing is what nesting is for', function () {
    $index = new RegistryIndex;
    $particle = store('beam.particle')->register('fragments', 'the fragments resource');
    $ops = store('beam.particle.fragments.ops')->register('download', 'the download op');

    $index->describe($particle)->describe($ops);

    // The interleaving the consumer wanted: a resource and its ops addressable in one keyspace, each
    // read landing in the registry that owns it. Neither key is ambiguous and neither registry holds a
    // mixed entry type — which is what saves ticket 01 §3 here rather than breaching it.
    expect($index->routeTo('beam.particle.fragments'))->toBe($particle)
        ->and($index->routeTo('beam.particle.fragments.ops.download'))->toBe($ops);
});

it('refuses a deeper root that would shadow an entry the shallower registry already holds', function () {
    $index = new RegistryIndex;
    $particle = store('beam.particle')->register('fragments.ops.download', 'reachable, for now');

    $index->describe($particle);

    expect(fn () => $index->describe(store('beam.particle.fragments.ops')))
        ->toThrow(ShadowedRegistryKey::class);

    // Refused rather than recorded: the entry is still readable through the door it was written at.
    expect($index->routeTo('beam.particle.fragments.ops.download'))->toBe($particle);
});

it('refuses the same collision described in the other order', function () {
    $index = new RegistryIndex;
    $index->describe(store('beam.particle.fragments.ops'));

    // Now the SHALLOWER registry arrives, already carrying the key the deeper root owns. Same defect,
    // opposite arrival order — a registry may be described before or after the one it nests with.
    expect(fn () => $index->describe(store('beam.particle')->register('fragments.ops.download', 'x')))
        ->toThrow(ShadowedRegistryKey::class);
});

it('names both roots and the entry that would have gone dark', function () {
    $index = new RegistryIndex;
    $index->describe(store('beam.particle')->register('fragments.ops.download', 'x'));

    try {
        $index->describe(store('beam.particle.fragments.ops'));
    } catch (ShadowedRegistryKey $shadowed) {
        expect($shadowed->key)->toBe('beam.particle.fragments.ops.download')
            ->and($shadowed->shallower)->toBe('beam.particle')
            ->and($shadowed->deeper)->toBe('beam.particle.fragments.ops')
            ->and($shadowed->getMessage())->toContain('two answers');

        return;
    }

    $this->fail('describing a shadowing root should have thrown');
});

it('does not treat its own zero-segment root as a shadow of everything', function () {
    // The index is a prefix of every key in the estate and its entries are ROOTS rather than entry
    // keys, so a naive check would refuse every registry there is, on a category error.
    $index = new RegistryIndex;

    expect(fn () => $index->describe(store('beam.particle')->register('fragments', 'x')))
        ->not->toThrow(ShadowedRegistryKey::class);
});

it('leaves a sibling root alone, segment-wise, however much the strings suggest otherwise', function () {
    $index = new RegistryIndex;
    $index->describe(store('beam.realm')->register('overlays.article', 'x'));

    // `beam.realms` is not under `beam.realm`, so nothing it could hold is shadowed by it.
    expect(fn () => $index->describe(store('beam.realms')))->not->toThrow(ShadowedRegistryKey::class);
});
