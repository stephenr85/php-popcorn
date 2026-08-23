<?php

use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Invocables\LocalInvocable;
use Rushing\Popcorn\Invocables\RemoteInvocable;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\HasRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\NamespaceUriKey;

/**
 * An invocable that ships its own key type — the `schemastud/laravel-json-ns` shape, where the
 * namespace URI IS the identity and could never survive `Key::parse()`. Present because a green suite
 * against `Key` proves nothing about the {@see RegistryKey} seam (ticket 23's amendment, found the hard
 * way in ticket 11).
 */
class UriKeyedInvocable implements HasRegistryKey, Invocable
{
    public function __construct(private string $uri) {}

    public function registryKey(): RegistryKey
    {
        return NamespaceUriKey::of($this->uri);
    }

    public function name(): string
    {
        return $this->uri;
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    public function invoke(array $input): array
    {
        return ['uri' => $this->uri];
    }
}

it('registers, finds, and invokes a local capability', function () {
    $registry = (new InvocableRegistry)->register(
        new LocalInvocable('echo', fn (array $in) => ['out' => $in['value'] ?? null]),
    );

    expect($registry->has('echo'))->toBeTrue()
        ->and($registry->names())->toBe(['echo'])
        ->and($registry->invoke('echo', ['value' => 42]))->toBe(['out' => 42]);
});

it('overrides a capability when re-registered under the same name', function () {
    $registry = (new InvocableRegistry)
        ->register(new LocalInvocable('tag', fn () => ['by' => 'local']))
        ->register(new RemoteInvocable('tag', Binding::Webhook, fn (string $n, array $in) => ['by' => 'webhook']));

    expect($registry->get('tag')->binding())->toBe(Binding::Webhook)
        ->and($registry->invoke('tag', []))->toBe(['by' => 'webhook']);
});

it('routes a remote invocable through its injected transport', function () {
    $seen = [];
    $remote = new RemoteInvocable('extract', Binding::Mcp, function (string $name, array $in) use (&$seen) {
        $seen = [$name, $in];

        return ['ok' => true];
    });

    expect($remote->invoke(['x' => 1]))->toBe(['ok' => true])
        ->and($seen)->toBe(['extract', ['x' => 1]]);
});

it('throws for an unknown capability', function () {
    (new InvocableRegistry)->get('missing');
})->throws(RegistryMiss::class);

it('rejects a remote invocable bound as local', function () {
    new RemoteInvocable('x', Binding::Local, fn () => []);
})->throws(InvalidArgumentException::class);

it('is a declared registry rooted at popcorn.invocables', function () {
    $declaration = IsRegistry::of(InvocableRegistry::class);

    expect(new InvocableRegistry)->toBeInstanceOf(Registry::class)
        ->and($declaration)->not->toBeNull()
        ->and($declaration->root)->toBe('popcorn.invocables')
        ->and($declaration->entryType)->toBe(Invocable::class);
});

it('keys relative in and absolute out, and names() strips the root back off', function () {
    $registry = (new InvocableRegistry)->register(
        new LocalInvocable('music.render', fn () => []),
    );

    expect(array_map('strval', $registry->keys()))->toBe(['popcorn.invocables.music.render'])
        ->and($registry->names())->toBe(['music.render'])
        ->and($registry->has('music.render'))->toBeTrue()
        ->and($registry->has('popcorn.invocables.music.render'))->toBeTrue();
});

it('reports an unparseable capability name as a miss, not a malformed key', function () {
    // The live shape: grounding-kernel builds `grounding.source.<type>` from a CUSTOMER's schema key.
    (new InvocableRegistry)->get('grounding.source.Web_Search');
})->throws(RegistryMiss::class);

it('never stamps a foreign key, and keeps the owner rendering', function () {
    $uri = 'https://schemastud.dev/ns/grounding/2';
    $registry = (new InvocableRegistry)->register(new UriKeyedInvocable($uri));

    expect(array_map('strval', $registry->keys()))->toBe([$uri])
        ->and($registry->names())->toBe([$uri])
        ->and($registry->has(NamespaceUriKey::of($uri)))->toBeTrue()
        ->and($registry->resolve(NamespaceUriKey::of($uri))->invoke([]))->toBe(['uri' => $uri]);
});

it('holds a foreign-keyed and a dotted entry in one registry without either reaching the other', function () {
    $uri = 'https://schemastud.dev/ns/grounding';
    $registry = (new InvocableRegistry)
        ->register(new UriKeyedInvocable($uri))
        ->register(new LocalInvocable('music.render', fn () => []));

    expect($registry->names())->toBe([$uri, 'music.render'])
        ->and($registry->tryResolve('music.render'))->not->toBeNull()
        ->and($registry->has(NamespaceUriKey::of($uri)))->toBeTrue();

    // The rendering is not the identity: a URI handed in as a STRING is a malformed dotted key, and
    // that is the whole of ticket 20 D3 — a foreign-keyed entry is reachable as a key object, never
    // through the global keyspace.
    expect(fn () => $registry->has($uri))->toThrow(InvalidRegistryKey::class);
});

it('forgets by key and by registrant', function () {
    $registry = (new InvocableRegistry)
        ->register('a', new LocalInvocable('a', fn () => []), by: 'pkg/one')
        ->register('b', new LocalInvocable('b', fn () => []), by: 'pkg/two');

    expect($registry->forget('a')->names())->toBe(['b'])
        ->and($registry->forgetBy('pkg/two')->names())->toBe([]);
});

it('filters every read through an installed authorizer, has() included', function () {
    $registry = (new InvocableRegistry)
        ->register('open', new LocalInvocable('open', fn () => []))
        ->register('gated', new LocalInvocable('gated', fn () => []), ability: 'invocables.gated')
        ->authorizeWith(new class implements Authorizer
        {
            public function allows(string $ability, RegistryKey $key): bool
            {
                return false;
            }
        });

    expect($registry->names())->toBe(['open'])
        ->and($registry->has('gated'))->toBeFalse()
        ->and($registry->unfiltered()->has('gated'))->toBeTrue();
});

it('reads its capability families as a TREE, segment-wise and not by string prefix', function () {
    $registry = new InvocableRegistry;

    $registry->register(new LocalInvocable('screening.source.denylist', fn (array $i): array => []));
    $registry->register(new LocalInvocable('screening.source.gazetteer', fn (array $i): array => []));

    // The trap the estate's `str_starts_with($name, 'screening.source.')` scans could not see:
    // `screening.sources` passes that check character-wise and is not a child of `screening.source`
    // at all. Nested's walk is segment-wise, so it never picks this up.
    $registry->register(new LocalInvocable('screening.sources', fn (array $i): array => []));

    expect(array_map('strval', $registry->children('screening.source')))
        ->toBe([
            'popcorn.invocables.screening.source.denylist',
            'popcorn.invocables.screening.source.gazetteer',
        ]);

    // ...and a child is one segment down, where a descendant is any depth.
    $registry->register(new LocalInvocable('screening.source.denylist.strict', fn (array $i): array => []));

    expect($registry->children('screening.source'))->toHaveCount(2)
        ->and($registry->descendants('screening.source'))->toHaveCount(3);
});
