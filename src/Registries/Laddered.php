<?php

namespace Rushing\Popcorn\Registries;

/**
 * A registry whose own {@see Registry::resolve()} is an ordered climb over declared tiers.
 *
 * Optional, and separate from {@see Registry} for the same reason {@see Gated}, {@see Nested} and
 * {@see Forgettable} are: a capability the type system carries. Named by registry-kernel ticket 33,
 * landed by ticket 36.
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
