<?php

use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Invocables\LocalInvocable;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registrant;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\ScopesRegistrant;

/**
 * The registrant vocabulary and its scoped default (docs-feedback ticket 06). The scope fills a null
 * `$by` and nothing else. The tenant-selector cases pin the property the scope must never break:
 * `forgetBy()` selects exactly what it selected before.
 */
function registrantStore(string $root = 'demo.things', OnKeyDuplicate $duplicates = OnKeyDuplicate::Supersede): BasicRegistry
{
    return new BasicRegistry(new IsRegistry(root: $root, onKeyDuplicate: $duplicates, description: 'test entries'));
}

it('records the scope registrant for a write that names nobody', function () {
    $store = registrantStore();

    Registrant::scoped('acme/package', fn () => $store->register('one', 'a'));

    expect($store->registrantOf('demo.things.one'))->toBe('acme/package');
});

it('never overrides an explicit registrant', function () {
    $store = registrantStore();

    Registrant::scoped('acme/package', fn () => $store->register('one', 'a', by: 'config:acme.things'));

    expect($store->registrantOf('demo.things.one'))->toBe('config:acme.things');
});

it('applies only inside the callback: a write after it, or a deferred closure, names nobody', function () {
    $store = registrantStore();
    $deferred = null;

    Registrant::scoped('acme/package', function () use ($store, &$deferred) {
        $deferred = fn () => $store->register('later', 'b');
    });
    $store->register('after', 'a');
    $deferred();

    expect(Registrant::current())->toBeNull()
        ->and($store->registrantOf('demo.things.after'))->toBeNull()
        ->and($store->registrantOf('demo.things.later'))->toBeNull();
});

it('nests: the innermost scope wins and leaving it restores the outer one', function () {
    $store = registrantStore();

    Registrant::scoped('acme/outer', function () use ($store) {
        $store->register('a', 1);
        Registrant::scoped('acme/inner', fn () => $store->register('b', 2));
        $store->register('c', 3);
    });

    expect($store->registrantOf('demo.things.a'))->toBe('acme/outer')
        ->and($store->registrantOf('demo.things.b'))->toBe('acme/inner')
        ->and($store->registrantOf('demo.things.c'))->toBe('acme/outer');
});

it('pops the scope when the callback throws', function () {
    try {
        Registrant::scoped('acme/package', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect(Registrant::current())->toBeNull();
});

it('returns what the callback returns', function () {
    expect(Registrant::scoped('acme/package', fn () => 42))->toBe(42);
});

it('names the scope registrant on a refused duplicate', function () {
    $store = registrantStore(duplicates: OnKeyDuplicate::Reject);
    $store->register('one', 'a', by: 'acme/first');

    expect(fn () => Registrant::scoped('acme/second', fn () => $store->register('one', 'b')))
        ->toThrow(fn (Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey $e) => expect($e->getMessage())->toContain('acme/second'));
});

it('reaches through a composing registry and the index, which both store in a BasicRegistry', function () {
    $invocables = new InvocableRegistry;
    $index = new RegistryIndex;

    Registrant::scoped('acme/package', function () use ($invocables, $index) {
        $invocables->register(new LocalInvocable('demo.say', fn () => ['said' => 'hi']));
        $index->register('demo.thing', 'x');
    });

    expect($index->registrantOf('demo.thing'))->toBe('acme/package')
        ->and($invocables->has('demo.say'))->toBeTrue();

    // InvocableRegistry exposes no registrantOf(); its teardown by the inherited name is the proof.
    $invocables->forgetBy('acme/package');

    expect($invocables->has('demo.say'))->toBeFalse();
});

it('refuses a scope that is not one of the three forms', function (string $registrant) {
    expect(fn () => Registrant::scoped($registrant, fn () => null))->toThrow(InvalidArgumentException::class);
})->with([
    'free word' => 'extend',
    'tenant selector' => 'Acme\\Hydrator:tenant-1:tenant-1:conduit-9',
    'bare class' => 'Acme\\Thing',
    'config with a space' => 'config acme.things',
    'empty' => '',
]);

it('accepts the three forms', function (string $registrant) {
    expect(Registrant::isVocabulary($registrant))->toBeTrue();
})->with([
    'package' => 'splicewire/laravel-beam',
    'config' => 'config:beam.core.renderings',
    'class' => 'class:App\\Data\\InvoiceData',
]);

it('builds the config and class forms', function () {
    expect(Registrant::fromConfig('beam.core.renderings'))->toBe('config:beam.core.renderings')
        ->and(Registrant::fromClass('\\App\\Data\\InvoiceData'))->toBe('class:App\\Data\\InvoiceData');
});

// ---------------------------------------------------------------------------------------------
// Teardown selectors: forgetBy() and keysBy() select exactly what they selected before
// ---------------------------------------------------------------------------------------------

it('leaves a per-tenant forgetBy exact when tenant entries are written inside a package scope', function () {
    $store = registrantStore();
    $tenantA = 'Acme\\Hydrator:tenant-a:tenant-a:conduit-1';
    $tenantB = 'Acme\\Hydrator:tenant-b:tenant-b:conduit-1';

    Registrant::scoped('acme/package', function () use ($store, $tenantA, $tenantB) {
        $store->register('seed', 'boot entry');
        $store->register('a', 'tenant a', by: $tenantA);
        $store->register('b', 'tenant b', by: $tenantB);
    });

    expect($store->keysBy($tenantA))->toHaveCount(1);

    $store->forgetBy($tenantA);

    expect($store->has('demo.things.a'))->toBeFalse()
        ->and($store->has('demo.things.b'))->toBeTrue()
        ->and($store->has('demo.things.seed'))->toBeTrue()
        ->and($store->registrantOf('demo.things.seed'))->toBe('acme/package')
        ->and($store->keysBy($tenantA))->toBe([]);
});

it('selects inherited entries by the package, the same as explicitly named ones', function () {
    $store = registrantStore();

    Registrant::scoped('acme/package', fn () => $store->register('inherited', 1));
    $store->register('explicit', 2, by: 'acme/package');
    $store->register('other', 3, by: 'acme/other');

    expect(array_map('strval', $store->keysBy('acme/package')))->toBe(['demo.things.inherited', 'demo.things.explicit']);

    $store->forgetBy('acme/package');

    expect(array_map('strval', $store->keys()))->toBe(['demo.things.other']);
});

// ---------------------------------------------------------------------------------------------
// ScopesRegistrant: the provider-side door, in the hook shape spatie's PackageServiceProvider has
// ---------------------------------------------------------------------------------------------

abstract class HookedLifecycle
{
    /** @var array<string, string|null> */
    public array $seen = [];

    public function __construct(public BasicRegistry $store) {}

    /** @return void */
    public function register()
    {
        $this->seen['register'] = Registrant::current();
        $this->store->register('registered', 'r');
    }

    /** @return void */
    public function boot()
    {
        $this->seen['boot'] = Registrant::current();
        $this->store->register('booted', 'b');
        $this->store->register('explicit', 'e', by: 'config:acme.things');
    }
}

it('scopes a hook-shaped provider register() and boot() to its package, and nothing after', function () {
    $provider = new class(registrantStore()) extends HookedLifecycle
    {
        use ScopesRegistrant;

        protected function registrant(): string
        {
            return 'acme/scoped-package';
        }
    };

    $provider->register();
    $provider->boot();
    $provider->store->register('after', 'a');

    expect($provider->seen)->toBe(['register' => 'acme/scoped-package', 'boot' => 'acme/scoped-package'])
        ->and($provider->store->registrantOf('demo.things.registered'))->toBe('acme/scoped-package')
        ->and($provider->store->registrantOf('demo.things.booted'))->toBe('acme/scoped-package')
        ->and($provider->store->registrantOf('demo.things.explicit'))->toBe('config:acme.things')
        ->and($provider->store->registrantOf('demo.things.after'))->toBeNull();
});
