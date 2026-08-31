<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * A conforming, GATED registry for the baked/lazy path (registry-kernel 73 phase B).
 *
 * It counts its own constructions, because the whole claim of laziness is *"nothing is constructed at
 * bake time"* and a test that cannot see a construction cannot tell laziness from eagerness.
 *
 * It is `Gated` on purpose: the authorizer push is the half that is not about visibility, and lazy
 * resolution is exactly where it could be dropped without anything noticing.
 *
 * @implements Registry<string>
 */
#[IsRegistry(
    root: 'lazy.demo',
    of: 'test entries, resolved on demand',
    arity: RegistryArity::PickOne,
)]
class LazyRegistry implements Gated, Registry
{
    public static int $constructions = 0;

    public ?Authorizer $seen = null;

    public bool $authorizerWasPushed = false;

    private BasicRegistry $entries;

    public function __construct()
    {
        self::$constructions++;

        $this->entries = BasicRegistry::for($this);
        $this->entries->register('one', 'first', by: 'fixture');
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->authorizerWasPushed = true;
        $this->seen = $authorizer;
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register($key, $entry, by: $by, ability: $ability);

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
}
