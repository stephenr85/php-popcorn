<?php

namespace Rushing\Popcorn\Registries\Registrars;

/**
 * Somewhere {@see CachedRegistrar} can put a registrar's writes and get them back.
 *
 * Deliberately the smallest thing that makes the decorator real. Ticket 07 D7 specifies a cache keyed by
 * the wrapped registrar's {@see \Rushing\Popcorn\Registries\Registrar::source()}, and a key implies a
 * store — a decorator with nowhere to put anything is not a decorator. Popcorn has no cache
 * infrastructure of any kind, so this is the first, and it is two methods rather than a driver system.
 *
 * `mustNotRequire: ["illuminate/*"]` is why it is an interface at all: the kernel cannot reach for
 * `Cache::`, so the only shape available is a seam a Laravel-side implementation fits into.
 *
 * ## Staleness is not here
 *
 * There is no `forget()`, no TTL and no version stamp, and that is now a decision rather than a ticket
 * boundary. Ticket 07 D7 handed invalidation to ticket 12, which dissolved without taking it; ticket 39
 * answered it by measurement: the cross-process discovery cache **already exists on the Laravel side** as
 * `Splicewire\Beam\Frame\FrameResourceManifest` — a per-host class-string manifest in `bootstrap/cache/`,
 * hooked into `ServiceProvider::optimizes()`, taking route-cache stale-until-cleared semantics. The
 * `optimize`/`optimize:clear` shape 12 D5 guessed at was right and was already built. So this seam does
 * not grow a persistent implementation, and the shipped {@see ArrayRegistrarCache} living exactly one
 * boot is the whole of it.
 *
 * ## A persistent implementation inherits one constraint
 *
 * What a registrar writes is serialisable *by construction* — both registrars read declarative sources
 * (ticket 07 D12) — but only for the entries they write themselves. An implementation that persists
 * across processes must be able to serialise the ENTRY, and a `$project` callable handed to
 * {@see AttributeRegistrar} can return anything, including an object holding a closure. That is the
 * implementation's problem to state, not this interface's to prevent.
 */
interface RegistrarCache
{
    /**
     * The writes recorded for `$source`, or null if nothing is cached for it.
     *
     * @return list<array{key: mixed, entry: mixed, by: string|null, ability: string|null}>|null
     */
    public function get(string $source): ?array;

    /** @param  list<array{key: mixed, entry: mixed, by: string|null, ability: string|null}>  $writes */
    public function put(string $source, array $writes): void;
}
