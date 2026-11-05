<?php

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\ForwardsToRegistryStore;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\ForwardingRegistry;

/**
 * `ForwardsToRegistryStore` (make-surface 05): the seven forwarders a composing registry used to write by
 * hand.
 *
 * The authorizer cases follow registry-kernel 74's acceptance form. A deny-all authorizer meets an
 * ability-carrying entry, and the result is read back through the registry's own public reads, never by
 * reflecting for the field. Delete the forward inside `authorizeWith()` and every case that installs an
 * authorizer goes red.
 */
beforeEach(function () {
    $this->denyAll = new class implements Authorizer
    {
        public function allows(string $ability, RegistryKey $key): bool
        {
            return false;
        }
    };

    $this->registry = (new ForwardingRegistry)
        ->register('order', 'the order')
        ->register('payroll', 'the payroll', ability: 'view-payroll');
});

it('forwards every read to the composed store, keys relative in and absolute out', function () {
    $registry = $this->registry;

    expect($registry->has('order'))->toBeTrue()
        ->and($registry->has('popcorn.forwarding.order'))->toBeTrue()
        ->and($registry->resolve('order'))->toBe('the order')
        ->and($registry->tryResolve('popcorn.forwarding.order'))->toBe('the order')
        ->and($registry->tryResolve('absent'))->toBeNull()
        ->and($registry->matches('popcorn.forwarding'))->toBe(['the order', 'the payroll'])
        ->and(array_map('strval', $registry->keys()))->toBe(['popcorn.forwarding.order', 'popcorn.forwarding.payroll']);
});

it('keeps the miss pair: resolve() throws where tryResolve() answers null', function () {
    expect(fn () => $this->registry->resolve('absent'))->toThrow(RegistryMiss::class)
        ->and($this->registry->tryResolve('absent'))->toBeNull();
});

it('passes an authorizer installed on the OUTER registry down to the composed store', function () {
    $registry = $this->registry;

    expect($registry->has('payroll'))->toBeTrue();

    expect($registry->authorizeWith($this->denyAll))->toBe($registry);

    expect($registry->has('payroll'))->toBeFalse()
        ->and($registry->tryResolve('payroll'))->toBeNull()
        ->and($registry->matches('popcorn.forwarding'))->toBe(['the order'])
        ->and(array_map('strval', $registry->keys()))->toBe(['popcorn.forwarding.order']);

    $registry->authorizeWith(null);

    expect($registry->has('payroll'))->toBeTrue();
});

it('offers the store\'s unfiltered read, so tooling still sees a hidden entry', function () {
    $registry = $this->registry->authorizeWith($this->denyAll);

    expect($registry->has('payroll'))->toBeFalse()
        ->and($registry->unfiltered()->has('payroll'))->toBeTrue()
        ->and($registry->unfiltered()->resolve('payroll'))->toBe('the payroll');
});

it('is filtered by the index when the authorizer is installed AFTER describe()', function () {
    $index = new RegistryIndex;
    $index->describe($this->registry);
    $index->authorizeWith($this->denyAll);

    expect($this->registry->has('payroll'))->toBeFalse()
        ->and(array_map('strval', $this->registry->keys()))->toBe(['popcorn.forwarding.order']);
});

it('is filtered by the index when the authorizer is installed BEFORE describe()', function () {
    $index = new RegistryIndex;
    $index->authorizeWith($this->denyAll);
    $index->describe($this->registry);

    expect($this->registry->has('payroll'))->toBeFalse()
        ->and(array_map('strval', $this->registry->keys()))->toBe(['popcorn.forwarding.order']);
});

it('refuses a store() that builds a new store on each call, instead of leaving the registry open', function () {
    $perCall = new #[IsRegistry(root: 'popcorn.forwarding.per-call')] class implements Gated, Registry
    {
        use ForwardsToRegistryStore;

        public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
        {
            return $this;
        }

        protected function store(): BasicRegistry
        {
            return BasicRegistry::for($this);
        }
    };

    expect(fn () => $perCall->authorizeWith($this->denyAll))
        ->toThrow(LogicException::class, 'returned a different object on a second call');
});

it('requires a Gated store, so a store that cannot take an authorizer does not compile in', function () {
    $store = (new ReflectionMethod(ForwardsToRegistryStore::class, 'store'))->getReturnType();

    expect($store)->toBeInstanceOf(ReflectionIntersectionType::class)
        ->and(array_map(fn (ReflectionNamedType $type) => $type->getName(), $store->getTypes()))
        ->toEqualCanonicalizing([Registry::class, Gated::class]);
});
