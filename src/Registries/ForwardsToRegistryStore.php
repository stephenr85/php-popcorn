<?php

namespace Rushing\Popcorn\Registries;

use LogicException;

/**
 * The {@see Registry} reads and the {@see Gated} wiring, forwarded to the store a registry composes.
 *
 * A registry that holds a {@see BasicRegistry} as a field used to forward these seven methods by hand,
 * one line each, in every package. This trait writes them once. The class keeps its own `register()`
 * (where it validates or shapes an entry), its domain sugar, and the one method the trait asks for:
 *
 * ```php
 * #[IsRegistry(root: 'pipelines', onKeyDuplicate: OnKeyDuplicate::Supersede)]
 * class PipelineRegistry implements Gated, Registry
 * {
 *     use ForwardsToRegistryStore;
 *
 *     private BasicRegistry $pipelines;
 *
 *     protected function store(): BasicRegistry
 *     {
 *         return $this->pipelines;
 *     }
 * }
 * ```
 *
 * ## It forwards; it stores nothing
 *
 * {@see Registry} refuses a storage trait, because most of the estate's registries hold two or three
 * collections. This trait holds no state. The class says which of its collections is the keyed store,
 * and the trait reads through it, so a class that also extends something else (`GraphStoreManager`
 * extends Laravel's `Manager`) can still use it.
 *
 * ## What it keeps from registry-kernel
 *
 * - **Keys go in relative and come out absolute** (ticket 20 D2). Every key is passed to the store
 *   unchanged; the store stamps the root and hands absolute keys back.
 * - **The authorizer is pushed on both edges** (ticket 20 D6). `RegistryIndex` pushes into a registry
 *   that is `Gated` and is not the index itself. That is the class using this trait, not its store, so
 *   {@see authorizeWith()} passes the authorizer down to where the filtering happens.
 * - **A class that composes a `Gated` store forwards the authorizer** (ticket 74 D1). {@see store()}
 *   returns `Registry&Gated`, so a store that cannot take an authorizer does not compile here.
 *
 * ## What the class must still do
 *
 * - **Declare `implements Gated, Registry`.** A trait cannot add an interface. A class that uses this
 *   trait without `Gated` gets an `authorizeWith()` the index never calls, and its reads stay open
 *   under a deny-all policy.
 * - **Return the same store from every {@see store()} call.** Hold it in a field. A store built per
 *   call would receive the authorizer and then be thrown away, so {@see authorizeWith()} checks this
 *   and throws rather than leave the registry silently open.
 * - **Write `register()` itself.** It is not forwarded here: it is where a registry checks or reshapes
 *   what it is given, and both registries that adopted this trait first do exactly that.
 *
 * ## Domain sugar and the miss pair
 *
 * `splicewire/laravel-beam`'s `RegistryConformanceAudit` (`miss-pair`) reads this trait as part of the
 * class, but it matches `$this-><property>->resolve(` against `$this-><property>->tryResolve(`. The
 * forwarders here call `$this->store()`, which matches neither. So when sugar in the class body reads
 * the store's `resolve()`, give it a `tryResolve()` twin in the class too, or have the sugar read through
 * `$this->resolve()` and `$this->tryResolve()` instead.
 *
 * @api
 */
trait ForwardsToRegistryStore
{
    /**
     * The composed store every forwarder reads through. Return the held field, the same object each call.
     */
    abstract protected function store(): Registry&Gated;

    public function has(RegistryKey|string $key): bool
    {
        return $this->store()->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store()->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store()->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->store()->matches($key);
    }

    public function keys(): array
    {
        return $this->store()->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->store()->unfiltered();
    }

    /**
     * {@see Gated}: pass the host's authorizer to the composed store, where the reads are filtered.
     *
     * @throws LogicException {@see store()} returned a different object on a second call
     */
    public function authorizeWith(?Authorizer $authorizer): static
    {
        $store = $this->store();
        $store->authorizeWith($authorizer);

        if ($this->store() !== $store) {
            throw new LogicException(sprintf(
                '`%s::store()` returned a different object on a second call, so the authorizer just pushed '
                    .'reached a store that no read will use. Hold the store in a field and return that field.',
                static::class,
            ));
        }

        return $this;
    }
}
