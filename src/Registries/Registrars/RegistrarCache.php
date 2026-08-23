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
 * There is no `forget()`, no TTL and no version stamp, and their absence is the ticket boundary rather
 * than an oversight (ticket 07 D7 hands invalidation to ticket 12, which then dissolved without taking
 * it — the question is live in this map's fog, owned by nobody, and the shape visible so far is that a
 * discovery cache is a disposable per-environment runtime artifact and probably wants Laravel's
 * `optimize`/`optimize:clear` hooks rather than a bespoke command). The shipped
 * {@see ArrayRegistrarCache} sidesteps it entirely by living exactly one boot, which is honest about
 * what has actually been decided.
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
