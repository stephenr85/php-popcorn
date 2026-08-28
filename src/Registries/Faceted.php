<?php

namespace Rushing\Popcorn\Registries;

/**
 * A registry whose entries each carry a declared classification you can enumerate BY — and which does
 * **not** shadow. Every entry bearing a facet value is returned; none of them hides another.
 *
 * Optional, and separate from {@see Registry} for the same reason {@see Gated}, {@see Nested},
 * {@see Filled} and {@see Laddered} are: a capability the type system carries.
 *
 * ## Read this against {@see Laddered} first — they are opposites, not siblings
 *
 * The two sit in this directory together and the next reader will meet them together, so the
 * distinction is the first thing this docblock owes them. Both name a set of strings that partition a
 * registry, and there the resemblance stops:
 *
 *   - `Laddered::rungs()` is a **climb**. It is ORDERED, outermost first, and the tier consulted first
 *     **shadows every tier after it**. A key resolves in exactly one rung; the others are hidden.
 *   - `Faceted::facets()` is a **partition that coexists**. It is UNORDERED, nothing is outer, and
 *     nothing shadows. A read by facet value returns *every* entry carrying it.
 *
 * `LensRegistry` states the difference better than an abstraction can: *"even `forId()` returns every
 * lens over an `@id`, since picking would mean this registry deciding which host is right about a
 * canonical it does not own."* A ladder exists precisely to pick. A facet exists precisely because
 * picking would be a lie.
 *
 * So do not read "facet" as a synonym for tier, rung, layer or level. If one value beats another, you
 * have a ladder, and `Laddered` is the interface. If they merely differ, you have a facet.
 *
 * ## It is DECLARATIVE — the kernel never filters
 *
 * `facets()` says what the axes are and what values they take. **Nothing in the kernel acts on that
 * map.** This copies `Laddered`'s posture deliberately and for the same reason: the beneficiaries hold
 * different notions of what a facet read *returns*, and a kernel that filtered for you would model one
 * and silently break the others. `CorpusStreamRegistry::ofType()` filters a flattened union across
 * conduits; `ScaffoldPackContentRegistry::copyable()` filters registrations and exposes exactly one
 * value of its axis as a named read. A generic `ofFacet($axis, $value)` would fit neither honestly.
 *
 * So `Faceted` buys **legibility**, not behaviour. Ticket 08 D1 ruled kernel *layering* out of scope on
 * a two-beneficiary bar; it did not rule out kernel *legibility*, and a registry whose entries carry an
 * enumerable classification can now say so instead of presenting an unclassified flat set.
 *
 * ## Facet values are names, not objects — and NOT the enum class-string
 *
 * Every beneficiary on disk classifies by a PHP enum today (`LensTier`, `CorpusStreamType`,
 * `FederationKind`, `OperationKind`, `AuthScheme`), so returning `LensTier::class` was the obvious
 * alternative and it is machine-resolvable to its cases, which a name list is not. It was rejected on
 * three counts:
 *
 *   1. **Resolvability is the hazard, not the feature.** `Laddered` says a rung name *"is not a key, it
 *      is not resolvable, and nothing looks an entry up by it"* — that is what keeps the kernel out.
 *      Handing back a live enum class is one short step from a kernel that iterates its cases and
 *      filters for you, which the section above exists to forbid.
 *   2. **It would make the enum mandatory.** `Laddered` chose names so that a registry whose tiers are
 *      branches inside one method could implement it without a refactor. The same applies here: a
 *      registry classifying by a boolean or a string tag must still be able to declare a facet.
 *      `ScaffoldPackContentRegistry::describe()` already emits `federation_kind` **and** `priced`
 *      side by side — one enum, one boolean, both genuinely axes.
 *   3. **A class-string loses the axis name.** `CorpusStreamType::class` does not tell a reader the
 *      registry calls that axis `type`, and the axis name is the half a human needs.
 *
 * ## Why a MAP, where `rungs()` returns a flat list
 *
 * This is the one place the shape deliberately departs from `Laddered`, and the reason is structural
 * rather than stylistic. **A ladder has exactly one axis by construction** — the climb — so its rung
 * names need no qualifier. A faceted registry may have several: `CompositionProfile` carries both
 * `axis(): Axis` and `arity(): Arity`, and `ScaffoldPackContentRegistry` classifies by federation kind
 * and by pricing. Flattening those into one list would be a category error — a reader could not tell
 * which axis a value belonged to, and two enums are free to collide on a case name.
 *
 * Keyed by axis, the single-axis case costs nothing (`['tier' => ['host-applied', ...]]`) and the
 * multi-axis case stays expressible. Naming the axis is also what makes the map readable next to a
 * `rungs()` list without either being mistaken for the other.
 *
 * ## Arity is orthogonal — a `PickOne` registry may still be faceted
 *
 * It is tempting to assume facets belong to registries that return many, and the estate says otherwise.
 * The six registries whose entries carry a classification span every arity: `RunAll` (`LensRegistry`,
 * `ScaffoldPackContentRegistry`), `ComposeMany` (`CorpusStreamRegistry`), and `PickOne`
 * (`ParticleOperationRegistry`, `ConduitProviderRegistry`, `CompositionProfileRegistry`).
 *
 * The reason they do not interact: **arity governs `resolve()`, facets partition `all()`.** A `PickOne`
 * registry shadows *per key* — one entry wins that address — and says nothing about how the surviving
 * entries classify. `ParticleOperation` is uniquely resolved by key and still carries a
 * read/write/task/stream `kind`. So do not read a facet declaration as a claim about arity, in either
 * direction.
 *
 * ## What it does NOT cover
 *
 * A classification that DECIDES which entry answers — where one value beats another — is a ladder, not
 * a facet; see the opening section. A per-entry property that no reader would ever enumerate the
 * registry by is just a field, and declaring it here claims a partition that nothing partitions.
 *
 * And, exactly as with `Laddered` (ticket 44 D0, ticket 57): **declaring `Faceted` is not a claim of
 * registry-ness.** It is a capability, read off the `implements` clause. A classifier that facets over
 * registries it does not own is as legitimate as `Laddered`'s position 3.
 */
interface Faceted
{
    /**
     * The classification axes this registry's entries carry, and the values each axis takes.
     *
     * Keyed by **axis name** — the noun the registry's own read uses (`tier` for
     * `LensRegistry::ofTier()`, `type` for `CorpusStreamRegistry::ofType()`) — mapping to that axis's
     * values as names.
     *
     * Unordered in both dimensions, and that is the contract, not an omission: no axis outranks
     * another and no value shadows another. Order the values however reads best — declaration order,
     * enum-case order, alphabetical — and rely on none of it.
     *
     * A facet name is for a reader: `popcorn:registries`, a conformance audit, a human. It is not a
     * key, it is not resolvable, and nothing looks an entry up by it.
     *
     * @return non-empty-array<string, non-empty-list<string>>
     */
    public function facets(): array;
}
