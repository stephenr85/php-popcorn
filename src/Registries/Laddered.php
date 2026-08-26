<?php

namespace Rushing\Popcorn\Registries;

/**
 * An ordered climb over declared tiers — either a {@see Registry} whose own {@see Registry::resolve()}
 * is that climb, or a ladder that reads over registries it does not own.
 *
 * Optional, and separate from {@see Registry} for the same reason {@see Gated}, {@see Nested} and
 * {@see Forgettable} are: a capability the type system carries. Named by registry-kernel ticket 33,
 * landed by ticket 36.
 *
 * ## `Laddered` does NOT imply `Registry` — the two positions
 *
 * This docblock said *"a registry whose own resolve()"* until registry-kernel ticket 57, and that was
 * falsified by **ticket 44 D0**, which ruled `Splicewire\Tower\Circuit\Capabilities\CapabilityLadder`
 * out of the registry population *while it declares this interface*. 44 corrected two other docblocks
 * that had leaned on the same false inference ({@see OnDuplicate::Admit} here in the kernel, and beam's
 * `RegistryConformanceAudit`); this is the third, and it is the one that matters most, because an audit
 * now reads it.
 *
 * 33's taxonomy gives two legal positions, and the difference is mechanical — read it off the
 * `implements` clause, not off the class name:
 *
 *   - **Position 2 — a registry that is itself a ladder.** `implements Laddered, Registry`, carries
 *     `#[IsRegistry]`, owns a root. `Splicewire\Beam\Particle\ParticleResourceRegistry` is the estate's.
 *   - **Position 3 — a ladder over registries it does not own.** `implements Laddered` and nothing
 *     else: no `#[IsRegistry]`, no root, no index membership, none of `Registry`'s seven methods. Its
 *     rungs cross a boundary between the registries it composes, which is precisely why it stays its own
 *     class instead of folding into one of them. `CapabilityLadder` is the estate's.
 *
 * So **declaring `Laddered` is not a claim of registry-ness**, and `Laddered`-without-`Registry` is not
 * a malformed registry — it is position 3, a shape the taxonomy sanctions. Beam's
 * `UndescribedRegistryAudit` reads exactly that pair to eject position 3 from the population its
 * structural test would otherwise suspect (ticket 57). That is a downward read — the audit sits above
 * this kernel — and it is why the ejection needs no exemption list naming a consumer-tier class.
 *
 * ## It is DECLARATIVE — the kernel never climbs
 *
 * `rungs()` says what the tiers are and in what order they are consulted. Nothing in the kernel acts
 * on that list. This is deliberate (ticket 33 D3): the four registries this interface was built for
 * hold three DIFFERENT policies, and a kernel that climbed for you would model one of them and
 * silently break the others. `VocabularyRegistry::$declared` is the case that proves it — it is a
 * policy set, not an alternative value source, so climbing it would turn declared-but-absent from a
 * hard error into a silent pass.
 *
 * So `Laddered` buys **legibility**, not behaviour. Ticket 08 D1 ruled kernel *layering* out of scope
 * on a two-beneficiary bar; it did not rule out kernel *legibility*, and a registry that resolves
 * through tiers can now say so instead of presenting a flat keyspace it does not have.
 *
 * ## Rungs are names, not objects
 *
 * `Ladders\Ladder::rungs()` and `MigrationLadder::rungs()` already return `string[]`, so this is the
 * estate's existing idiom rather than a new one. Returning names is also what lets a registry whose
 * tiers are branches inside one method — rather than composed `Rung` objects — implement this without
 * a refactor. Do not add a `Rung`-returning variant without pricing that (ticket 33 D3).
 *
 * A rung name is for a reader: `popcorn:registries`, a conformance audit, a human. It is not a key,
 * it is not resolvable, and nothing looks an entry up by it.
 *
 * ## What it does NOT cover
 *
 * A tier that REPLACES the whole entry set rather than shadowing per key is not a ladder over one
 * keyspace — it is a config fallback, and if it has no keyspace at all it is not a registry either
 * (ticket 36, `config('data-schemas.strategies')`).
 */
interface Laddered
{
    /**
     * The tiers this registry resolves through, **outermost first** — the tier consulted first,
     * whose entries shadow every tier after it.
     *
     * The order matches {@see RegistryArity} lists, which are also outermost-first (ticket 47).
     *
     * @return non-empty-list<string>
     */
    public function rungs(): array;
}
