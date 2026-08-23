<?php

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Exceptions\AmbiguousRegistryMatch;
use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Rushing\Popcorn\Registries\Exceptions\MissReason;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\Filled;
use Rushing\Popcorn\Registries\Forgettable;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Nested;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\DeclaredRegistry;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\UndeclaredRegistry;

/**
 * The `Registry` contract (registry-kernel ticket 23, landing ticket 01 as amended by 05, 06, 07, 08
 * and 09). These assert the decisions that are easy to erode: what is on the interface and what is
 * deliberately off it, the registration-order guarantee, the three duplicate policies, and the
 * authorization filter's two load-bearing edges — that `has()` filters too, and that an ungated entry
 * never reaches the authorizer at all.
 */
function registry(
    OnDuplicate $onDuplicate = OnDuplicate::Supersede,
    Optionality $optionality = Optionality::Optional,
): BasicRegistry {
    return new BasicRegistry(new IsRegistry(
        root: 'beam.resources',
        of: 'test entries',
        arity: RegistryArity::PickOne,
        onDuplicate: $onDuplicate,
        optionality: $optionality,
    ));
}

/** A store rooted somewhere other than `beam.resources`, for the segment-wise near-miss cases. */
function rootedAt(string $root): BasicRegistry
{
    return new BasicRegistry(new IsRegistry(
        root: $root,
        of: 'test entries',
        arity: RegistryArity::PickOne,
    ));
}

it('keeps attach, forget and the tree walks OFF the base interface', function () {
    $methods = array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        (new ReflectionClass(Registry::class))->getMethods(),
    );

    sort($methods);

    expect($methods)->toBe(['has', 'keys', 'matches', 'register', 'resolve', 'tryResolve', 'unfiltered'])
        ->and(interface_exists(Forgettable::class))->toBeTrue()
        ->and(interface_exists(Nested::class))->toBeTrue()
        // Landed by ticket 24. The assertion that matters is no longer that `Filled` is absent, but
        // that `attach`/`registrars` stayed OFF the base interface when it arrived — which is what the
        // method list above pins.
        ->and(interface_exists(Filled::class))->toBeTrue();
});

it('declares root, of, arity, entryType — and no seam', function () {
    $declaration = IsRegistry::of(DeclaredRegistry::class);

    expect($declaration)->toBeInstanceOf(IsRegistry::class)
        ->and($declaration->root)->toBe('beam.resources')
        ->and($declaration->of)->toBe('test entries, for the contract suite')
        ->and($declaration->arity)->toBe(RegistryArity::RunAll)
        ->and($declaration->entryType)->toBe('string')
        ->and($declaration->rootKey()->segments())->toBe(['beam', 'resources'])
        ->and(property_exists($declaration, 'seam'))->toBeFalse()
        ->and(property_exists($declaration, 'where'))->toBeFalse()
        ->and(property_exists($declaration, 'registerHint'))->toBeFalse();
});

it('reaches OnDuplicate and Optionality as declared registry properties, not just loose enums', function () {
    $store = BasicRegistry::for(DeclaredRegistry::class);

    expect($store->declaration()->onDuplicate)->toBe(OnDuplicate::Reject)
        ->and($store->declaration()->optionality)->toBe(Optionality::Required)
        ->and($store->root())->toBe('beam.resources');
});

it('refuses to compose a store for a class that declares nothing, rather than inventing a root', function () {
    expect(fn () => BasicRegistry::for(UndeclaredRegistry::class))
        ->toThrow(InvalidArgumentException::class, '#[IsRegistry]');
});

it('round-trips the registrant into the miss diagnostics', function () {
    $store = registry(OnDuplicate::Admit)
        ->register('beam.resources.order', 'a', by: 'splicewire/laravel-beam')
        ->register('beam.resources.order', 'b', by: 'rushing/laravel-commerce');

    try {
        $store->resolve('beam.resources.order');
        $this->fail('expected an ambiguous match');
    } catch (AmbiguousRegistryMatch $miss) {
        expect($miss->candidates)->toBe([
            ['key' => 'beam.resources.order', 'by' => 'splicewire/laravel-beam'],
            ['key' => 'beam.resources.order', 'by' => 'rushing/laravel-commerce'],
        ]);
    }
});

it('throws a miss for an absent key and returns null from tryResolve', function () {
    $store = registry();

    expect(fn () => $store->resolve('beam.resources.order'))
        ->toThrow(RegistryMiss::class)
        ->and($store->tryResolve('beam.resources.order'))->toBeNull();
});

it('distinguishes an empty Required registry from a bad key', function () {
    try {
        registry(optionality: Optionality::Required)->resolve('beam.resources.order');
        $this->fail('expected an unpopulated miss');
    } catch (RegistryMiss $miss) {
        expect($miss->reason)->toBe(MissReason::Unpopulated);
    }
});

it('makes ambiguity reachable at an EXACT key only under Admit', function () {
    $admit = registry(OnDuplicate::Admit)
        ->register('beam.resources.order', 'a')
        ->register('beam.resources.order', 'b');

    $supersede = registry()
        ->register('beam.resources.order', 'a')
        ->register('beam.resources.order', 'b');

    expect(fn () => $admit->resolve('beam.resources.order'))->toThrow(AmbiguousRegistryMatch::class)
        ->and($supersede->resolve('beam.resources.order'))->toBe('b');
});

it('refuses a duplicate at write time under Reject, naming both registrants', function () {
    $store = registry(OnDuplicate::Reject)->register('beam.resources.order', 'a', by: 'splicewire/laravel-beam');

    expect(fn () => $store->register('beam.resources.order', 'b', by: 'rushing/laravel-commerce'))
        ->toThrow(DuplicateRegistryKey::class, 'by splicewire/laravel-beam');
});

it('reports a key naming a BRANCH as ambiguous rather than absent', function () {
    $store = registry()
        ->register('beam.resources.order', 'a')
        ->register('beam.resources.invoice', 'b');

    expect(fn () => $store->resolve('beam.resources'))->toThrow(AmbiguousRegistryMatch::class);
});

it('never walks UP a key: resolution is flat even though the keyspace is a tree', function () {
    $store = registry()->register('beam.resources', 'the branch entry');

    expect($store->tryResolve('beam.resources.order'))->toBeNull()
        ->and($store->resolve('beam.resources'))->toBe('the branch entry');
});

it('guarantees matches() in registration order, and matches at-or-under segment-wise', function () {
    // Rooted at `beam` rather than `beam.resources` so `beam.realms.article` can be registered at all:
    // since ticket 20, a key outside the declared root is read as RELATIVE and stamped, so the
    // near-miss sibling has to live inside the root to be a near-miss rather than a new subtree.
    $store = rootedAt('beam')
        ->register('beam.resources.order', 'order')
        ->register('beam.realms.article', 'not this one')
        ->register('beam.resources', 'branch')
        ->register('beam.resources.invoice.line', 'line')
        ->register('beam.resources.invoice', 'invoice');

    expect($store->matches('beam.resources'))->toBe(['order', 'branch', 'line', 'invoice']);
});

it('does not feed a superseded entry to a read, and records what it displaced', function () {
    $store = registry()
        ->register('beam.resources.order', 'first', by: 'splicewire/laravel-beam')
        ->register('beam.resources.order', 'second', by: 'rushing/laravel-commerce');

    expect($store->matches('beam.resources'))->toBe(['second'])
        ->and($store->superseded('beam.resources.order'))->toHaveCount(1)
        ->and($store->superseded('beam.resources.order')[0]->entry)->toBe('first')
        ->and($store->superseded('beam.resources.order')[0]->by)->toBe('splicewire/laravel-beam');
});

it('destroys the superseded history along with the entry, because a survivor IS the leak', function () {
    $store = registry()
        ->register('beam.resources.order', 'first', by: 'splicewire/laravel-beam')
        ->register('beam.resources.order', 'second', by: 'splicewire/tower');

    expect($store->superseded('beam.resources.order'))->toHaveCount(1);

    $store->forget('beam.resources.order');

    expect($store->has('beam.resources.order'))->toBeFalse()
        ->and($store->superseded('beam.resources.order'))->toBe([]);
});

it('forgets by registrant, unscoped — provenance selects, it does not authorize', function () {
    $store = registry()
        ->register('beam.resources.order', 'order', by: 'rushing/laravel-commerce')
        ->register('beam.resources.invoice', 'invoice', by: 'rushing/laravel-commerce')
        ->register('beam.resources.article', 'article', by: 'splicewire/laravel-beam');

    $store->forgetBy('rushing/laravel-commerce');

    expect($store->matches('beam.resources'))->toBe(['article']);
});

it('walks the tree by segments, never by characters', function () {
    $store = rootedAt('beam')
        ->register('beam.resources.order', 'order')
        ->register('beam.resources.invoice.line', 'line')
        ->register('beam.realms.article', 'article');

    $children = array_map('strval', $store->children('beam.resources'));
    $descendants = array_map('strval', $store->descendants('beam.resources'));

    expect($children)->toBe(['beam.resources.order', 'beam.resources.invoice'])
        ->and($descendants)->toBe([
            'beam.resources.order',
            'beam.resources.invoice',
            'beam.resources.invoice.line',
        ]);
});

it('filters every actor-facing read identically, has() included', function () {
    $store = registry()
        ->register('beam.resources.order', 'order')
        ->register('beam.resources.payroll', 'payroll', ability: 'view-payroll')
        ->authorizeWith(new class implements Authorizer
        {
            public function allows(string $ability, RegistryKey $key): bool
            {
                return false;
            }
        });

    expect($store->has('beam.resources.payroll'))->toBeFalse()
        ->and($store->tryResolve('beam.resources.payroll'))->toBeNull()
        ->and($store->matches('beam.resources'))->toBe(['order'])
        ->and(array_map('strval', $store->keys()))->toBe(['beam.resources.order']);
});

it('renders a filtered miss as an absent one, leaking nothing but the reason', function () {
    $store = registry()
        ->register('beam.resources.payroll', 'payroll', ability: 'view-payroll')
        ->authorizeWith(new class implements Authorizer
        {
            public function allows(string $ability, RegistryKey $key): bool
            {
                return false;
            }
        });

    try {
        $store->resolve('beam.resources.payroll');
        $this->fail('expected a filtered miss');
    } catch (RegistryMiss $miss) {
        expect($miss->reason)->toBe(MissReason::Filtered)
            ->and($miss->getMessage())->toBe(RegistryMiss::absent('beam.resources.payroll')->getMessage());
    }
});

it('never consults the authorizer for an ungated entry', function () {
    $authorizer = new class implements Authorizer
    {
        public array $asked = [];

        public function allows(string $ability, RegistryKey $key): bool
        {
            $this->asked[] = $ability;

            return true;
        }
    };

    $store = registry()
        ->register('beam.resources.order', 'order')
        ->register('beam.resources.payroll', 'payroll', ability: 'view-payroll')
        ->authorizeWith($authorizer);

    $store->matches('beam.resources');

    expect($authorizer->asked)->toBe(['view-payroll']);
});

it('receives the declared ability and the key, and neither the actor nor the entry', function () {
    $authorizer = new class implements Authorizer
    {
        /** @var list<array{string, string}> */
        public array $seen = [];

        public function allows(string $ability, RegistryKey $key): bool
        {
            $this->seen[] = [$ability, (string) $key];

            return true;
        }
    };

    registry()
        ->register('beam.resources.payroll', 'the entry value', ability: 'view-payroll')
        ->authorizeWith($authorizer)
        ->resolve('beam.resources.payroll');

    // The signature is the assertion: an implementor cannot reach the actor or the entry value from
    // here, which is what makes a PickOne hit and a 400-entry enumeration cost the same (09 D1).
    expect($authorizer->seen)->toBe([['view-payroll', 'beam.resources.payroll']]);
});

it('offers an explicit, named unfiltered read for the doctor and the gate', function () {
    $store = registry()
        ->register('beam.resources.payroll', 'payroll', ability: 'view-payroll')
        ->authorizeWith(new class implements Authorizer
        {
            public function allows(string $ability, RegistryKey $key): bool
            {
                return false;
            }
        });

    expect($store->has('beam.resources.payroll'))->toBeFalse()
        ->and($store->unfiltered()->has('beam.resources.payroll'))->toBeTrue()
        ->and($store->unfiltered()->resolve('beam.resources.payroll'))->toBe('payroll');
});

it('accepts a RegistryKey wherever it accepts a string', function () {
    $store = registry()->register(Key::parse('beam.resources.order'), 'order');

    expect($store->resolve(Key::parse('beam.resources.order')))->toBe('order')
        ->and($store->has('beam.resources.order'))->toBeTrue();
});
