<?php

namespace Rushing\Popcorn\Registries;

/**
 * The seam through which a host answers "may the caller see this entry?" — the one thing no static
 * declaration can express, because the answer depends on who is asking.
 *
 * (This sentence used to read "the ONE thing a tag cannot express", which quietly implied the kernel
 * had tags. It never did, and per ticket 31 it never will — see {@see IsRegistry}'s "What is
 * deliberately not a field".)
 *
 * Popcorn does not gate. It has no actor, no permission model and no `illuminate/*` dependency to
 * borrow one from; it exposes this and a host supplies the policy. `splicewire/laravel-beam`
 * registers an implementation that consults its particle abilities — see registry-kernel ticket 09.
 *
 * ## What this deliberately does NOT receive
 *
 * Not the actor, and not the resolved entry.
 *
 * **No actor**, because "the current user" is not a transport-neutral idea and a kernel that typed
 * one would be wrong on the transport that has none. Beam's {@see \Splicewire\Beam\Authorization\ActorPort}
 * is the shipped statement of this: an ability resolver reaching for ambient authentication
 * "would silently answer a *different* question on each transport". The implementor closes over its
 * own actor source; the kernel never learns there is one.
 *
 * **No entry value**, because Popcorn must not construct a value in order to decide whether you may
 * see it. That is what makes a `PickOne` hit and a 400-entry enumeration cost the same, and it is
 * why this seam is safe to call per-entry rather than needing a batch form.
 *
 * What it gets instead is the ability string the entry DECLARED at registration, plus its key. The
 * key is passed because the seam has it and a host override may care — the same reason
 * {@see \Rushing\McpRegistry\Concerns\AuthorizesTools} passes a tool class-string it then ignores.
 *
 * ## One filter point, one answer
 *
 * Every actor-facing read filters identically — `has()`, `resolve()`, `tryResolve()`, `matches()`
 * and `keys()`. A hidden entry is reported {@see Exceptions\MissReason::Filtered}, whose message is
 * byte-identical to {@see Exceptions\MissReason::Absent} precisely so that enumeration and a direct
 * hit cannot disagree about whether a key exists. An unfiltered `has()` would be an existence
 * oracle that undoes the whole policy through one boolean.
 *
 * Tooling that must see everything — the doctor, the {@see Optionality} audit, the surgeon gate —
 * reads through the registry's explicit unfiltered accessor rather than through a special case
 * here. That path is artisan-only, under the estate's stated trusted-shell policy.
 *
 * ## Registration is never gated
 *
 * This answers about READS only. Registrars run eagerly at their owner's `boot()`, where there is
 * no actor and no request; a provider boot that failed on permissions would be a bootstrap ordering
 * bug wearing a security costume.
 *
 * ## The result of this filter is NOT cacheable
 *
 * It is per-actor by construction. A registrar's output may be cached — it is actor-free and
 * serialisable — but the cache must sit UNDER this seam, never over it. A cached discovery result
 * that has already been authorized is poisoned for the next caller.
 */
interface Authorizer
{
    /**
     * Whether the caller may see the entry declaring `$ability`.
     *
     * **Never called for an ungated entry.** An entry that declared no ability short-circuits inside
     * the registry and is always allowed, so `$ability` is non-nullable here and the type carries
     * the fact. That short-circuit is what makes installing an authorizer incapable of narrowing an
     * already-open surface — the property that lets this default to open safely.
     *
     * Return a bool and construct nothing: the deny SHAPE belongs to the caller's transport, which
     * answers a forbidden status, an omitted listing entry or a bare miss according to its own
     * dialect. A kernel that built the denial would force one transport to speak another's.
     */
    public function allows(string $ability, RegistryKey $key): bool;
}
