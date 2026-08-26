<?php

namespace Rushing\Popcorn\Registries;

use Rushing\Popcorn\Registries\Exceptions\AmbiguousRegistryMatch;
use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;

/**
 * The one primitive: a keyed collection something registers INTO and something else reads OUT of,
 * addressed by {@see RegistryKey}. Everything the estate calls a Registry, a Manifest, a Pipeline or
 * a Store is this (canon: `the-seam-is-a-registry`); what differs between them is the ARITY of a
 * read, not the suffix on the class name.
 *
 * ## An interface, never a base class
 *
 * Deliberate, and the deciding case is live: `rushing/laravel-graphine`'s `GraphStoreManager` already
 * extends Laravel's `Manager` and so can extend nothing of ours. A storage trait fares no better — it
 * fits `InvocableRegistry` and almost nothing else, because the estate's registries hold two and three
 * collections apiece. So the type is an interface, and {@see BasicRegistry} is a concrete
 * implementation you HOLD AS A FIELD when you want the behaviour. Composition is sanctioned
 * (registry-kernel ticket 01, D1).
 *
 * ## Six methods, and what is deliberately not here
 *
 * - **No `attach()` / `registrars()`** — they live on {@see Filled}, because a registry that only ever
 *   hand-registers should not have to answer them (ticket 07 D8).
 * - **No `forget()` / `forgetBy()`** — they live on {@see Forgettable}. A registry that cannot be torn
 *   down simply does not implement it, and the type system carries the fact (ticket 08 D8).
 * - **No `children()` / `descendants()`** — they live on {@see Nested} (ticket 05).
 * - **No miss policy.** `resolve()` throws and `tryResolve()` returns null; the estate's live split is
 *   deliberate on both sides, and the method pair honours it without a flag (ticket 01 D7, 06 D7).
 *   **Which half a host reaches for is a rule, not a taste** — it turns on where the key came from,
 *   and it is written on {@see tryResolve()} (ticket 61).
 * - **No `rank`, `priority` or `order` field.** Registration order IS the ordering key, and
 *   {@see matches()} guarantees it (ticket 08 D4).
 * - **No merge.** `entryType` is `'mixed'` across most of the census, so a kernel that merged would
 *   have to know entry types it cannot know (ticket 06).
 * - **No normalisation.** Parsing lives on {@see Key}; a registry that redefined what a key IS breaks
 *   the index the moment a key crosses a package boundary (ticket 05).
 *
 * ## Declaration is an attribute, not a method
 *
 * A registry declares its `root`, what it is `of`, its arity, its duplicate policy and its optionality
 * through {@see IsRegistry} on the class — the only mechanism a static walk can read without booting,
 * which is what lets the surgeon gate check conformance (ticket 01 §4, ticket 14).
 *
 * ## Authorization: every actor-facing read filters identically
 *
 * `has()`, `resolve()`, `tryResolve()`, `matches()` and `keys()` all pass through the host's
 * {@see Authorizer}, if one is installed. Not four of five: an unfiltered `has()` is an existence
 * oracle that undoes the policy through one boolean, and a hidden entry therefore reports
 * {@see Exceptions\MissReason::Filtered}, whose message is byte-identical to `Absent`.
 *
 * Three properties hold around that, and each is load-bearing:
 *
 * - **An entry that declared no `ability` short-circuits before the authorizer is consulted**, which is
 *   what makes installing an authorizer incapable of narrowing an already-open surface — the property
 *   that lets the default be open (ticket 09 D2).
 * - **Registration is never gated.** This filters READS. Registrars run eagerly at their owner's
 *   `boot()`, where there is no actor and no request (ticket 09 D4).
 * - **A filtered read is never cacheable.** It is per-actor by construction; a registrar's cache sits
 *   UNDER this seam, never over it (ticket 09 D5).
 *
 * Tooling that must see everything — the doctor, the {@see Optionality} audit, the surgeon gate — reads
 * through {@see unfiltered()} rather than through a hidden special case. The authorizer itself is
 * installed once, on the index, not per registry: per-registry means a registry that forgets to wire it
 * is silently open (ticket 09 D7; the attachment point is ticket 20's).
 *
 * ## `TEntry` — what the runtime declaration cannot say, said to the type system
 *
 * {@see IsRegistry}'s `entryType` names what a registry holds, but an attribute argument is a string
 * read at runtime: it cannot give `$registry->resolve('x')` a return type, because attributes and
 * runtime methods do not participate in PHP's type system at all (registry-kernel ticket 34 D4). The
 * docblock generic is the half that can, and the two are one fact from two sides — `entryType` for the
 * index, the conformance audit and the doctor; `TEntry` for the IDE, the analyser and the call site.
 *
 * A port names the type once and every call site downstream of it is typed:
 *
 * ```php
 * #[IsRegistry(root: 'beam.particle.resources', of: '…', entryType: ResourceDefinition::class)]
 * interface ResourceRegistry extends Registry<ResourceDefinition> {}
 *
 * $registry->resolve('invoices');   // ResourceDefinition, not mixed
 * $registry->matches('');           // list<ResourceDefinition>, not list<mixed>
 * ```
 *
 * A registry that genuinely holds anything writes `Registry<mixed>` and loses nothing — which is most
 * of the census, where `entryType` is `'mixed'` for the same reason.
 *
 * **A second interface to say this was refused.** `Typed` would be a third place the entry type is
 * written, and a third place it can drift from the other two (ticket 07 D4 / 01 D10).
 *
 * @template TEntry
 */
interface Registry
{
    /**
     * Write an entry under `$key`.
     *
     * `$by` names the REGISTRANT — the package, provider or config key that put this here. Every write
     * carries it, and it is not decoration: it is what makes last-wins auditable, what makes a miss
     * message actionable, and the property whose absence is the standing indictment of the Windows
     * Registry, where no key records what put it there (ticket 06 D11). `null` is legal and degrades
     * exactly those two things.
     *
     * `$ability` declares that reading this entry is GATED on that authorization token. A named
     * argument rather than an attribute, because a registry entry is often a value, an array or a
     * closure with no class to hang one on (ticket 09 D13). `null` ⇒ ungated, and ungated entries never
     * reach the authorizer at all.
     *
     * What a duplicate key does is declared per registry via {@see OnDuplicate}, not chosen here — the
     * estate ships all three behaviours with argued docblocks.
     *
     * @param  TEntry  $entry
     *
     * @throws DuplicateRegistryKey under {@see OnDuplicate::Reject}
     */
    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static;

    /** Whether a visible entry exists at exactly `$key`. Filters, for the reason above. */
    public function has(RegistryKey|string $key): bool;

    /**
     * The one entry at `$key`.
     *
     * @return TEntry
     *
     * @throws RegistryMiss no entry, or the registry is `Required` and empty, or the only entries are
     *                      hidden from this caller
     * @throws AmbiguousRegistryMatch several entries answer and none of them is THE answer — either an
     *                                `Admit` duplicate at an exact key, or a key naming a branch rather
     *                                than a leaf
     */
    public function resolve(RegistryKey|string $key): mixed;

    /**
     * The same lookup, `null` on a miss.
     *
     * **Ambiguity still throws.** A miss is a question with no answer and the caller opted into
     * handling that; ambiguity is a question with several, and answering `null` would be precisely the
     * "null-to-empty" Tower's `CapabilityResolutionError` exists to refuse. A silent null is how a
     * duplicate registration survives to production.
     *
     * ## Which half a host reaches for — the rule, not a preference (ticket 61)
     *
     * **A key the CODE chose is a `resolve()`. A key that came from OUTSIDE is a `tryResolve()`.**
     * The split is about where the key came from, never about how the caller feels about exceptions.
     * A literal, a config value, an enum case — the code asserted that entry exists, so a miss is a
     * wiring bug and should be loud. A URL segment, a query parameter, a request body field, a stored
     * user selection — those can always miss, an escaping {@see RegistryMiss} is a 500, and the honest
     * answer is the host's own 404.
     *
     * Two things a request-facing caller must handle that a `resolve()` caller never sees:
     *
     * - **A string that is not a key at all.** `Key::parse()` throws {@see Exceptions\InvalidRegistryKey}
     *   BEFORE any miss is considered — uppercase is rejected rather than folded, `/` is not a key
     *   character. That is right at a declaration site and wrong on a URL segment, where "not a legal
     *   key" and "no such key" are the same 404. Gate with `Key::tryParse()` first; never relax the
     *   parser to make the caller's life easier.
     * - **Ambiguity still throws, on purpose.** Two entries answering one user-supplied key is a
     *   duplicate registration, not a bad request.
     *
     * ## A port that wraps this must carry the PAIR across
     *
     * A registry keeping its own vocabulary over the kernel (`get()`, `for()`, `definition()`) must
     * publish both halves — the throwing accessor over `resolve()` AND its nullable twin over
     * `tryResolve()`, on Laravel's `findOrFail()`/`find()` split. Publishing only the throwing half
     * leaves a host either catching a kernel exception it never imported or paying a
     * `has()`-then-`get()` double lookup, and it is how ticket 38's sweep turned two asserted 404s in
     * the flagship into 500s: the port's miss changed type under a host's `catch`, and no nullable
     * accessor existed to move to. Whichever half the port already had keeps its behaviour; the
     * migration ADDS the missing one, it never re-points the existing one.
     *
     * @return TEntry|null
     *
     * @throws AmbiguousRegistryMatch
     */
    public function tryResolve(RegistryKey|string $key): mixed;

    /**
     * Every LIVE, visible entry at or under `$key` — segment-wise, so `beam.realms` is not under
     * `beam.realm` however much the strings suggest otherwise.
     *
     * **Returns registration order, guaranteed and documented.** That guarantee is the whole ordering
     * story: it is what `descriptors()`-style foundation-first rendering needs, and it is why there is
     * no `rank` field to drift out of sync with it (ticket 08 D4).
     *
     * Superseded entries are never included. Arity is about live entries; supersession is history, and
     * conflating them is how a `RunAll` registry starts running dead entries (ticket 01 D9).
     *
     * @return list<TEntry>
     */
    public function matches(RegistryKey|string $key): array;

    /**
     * Every visible key holding a live entry, in registration order.
     *
     * @return list<RegistryKey>
     */
    public function keys(): array;

    /**
     * The same registry with no authorizer — the explicit, named, public escape for the doctor, the
     * `Optionality` audit and the surgeon gate (ticket 09 D11).
     *
     * Blunt on purpose. The rejected alternative was an implicit rule ("filter only when an actor is
     * present"), which makes the security-relevant behaviour depend on ambient state nobody can see at
     * the call site. Under the estate's stated trusted-shell policy this path is artisan-only.
     *
     * @return Registry<TEntry>
     */
    public function unfiltered(): Registry;
}
