<?php

namespace Rushing\Popcorn\Registries;

/**
 * The RESOLUTION arity of a registry — how many of its registered entries a read engages OUT.
 *
 * This is the axis the estate's "Registry" vs "Manifest" class-name split was really groping toward:
 * both are the SAME primitive (a registry at a seam), and arity — not the suffix — is what actually
 * differs (canon: `the-seam-is-a-registry`). Declaring it makes that legible instead of leaving it
 * buried in the shape of a read method.
 *
 * ## Declared metadata, not an enforced constraint
 *
 * The contract gives EVERY registry both a one-entry read ({@see Registry::resolve()}) and an
 * all-entries read ({@see Registry::matches()}). Arity says which one this registry is FOR — it is what
 * the index renders and what a consumer reads to know whether it is looking at a lookup table or a
 * pipeline — and the kernel does not refuse the other read. Ticket 04 found this out by self-hosting
 * the index, whose live `RunAll` and claimed `PickOne` were never actually in conflict.
 *
 * Orthogonal to {@see Optionality}, which asks the separate question "is EMPTY an error?" — OSGi's
 * split, and the one this enum was missing.
 *
 * There is no `seam` beside it. `ManifestSeam` is deleted: its seven cases were a census of the
 * pre-Popcorn world — how ~55 bespoke registries happened to get filled *because none of them shared a
 * contract* — and once they share one, "how do I inject?" has a uniform answer, which is that you call
 * `register()` (ticket 07 D1).
 */
enum RegistryArity: string
{
    /** Resolve ONE entry — most-specific/keyed wins, a miss falls back to a generic default. */
    case PickOne = 'pick-one';

    /** Compose MANY entries — an ordered chain each entry contributes to (fails loud, not silently skipped). */
    case ComposeMany = 'compose-many';

    /** Run ALL entries — a conjunction/enumeration; every registered entry is engaged. */
    case RunAll = 'run-all';

    /** A one-line account of what a read of this arity does. */
    public function blurb(): string
    {
        return match ($this) {
            self::PickOne => 'Read resolves ONE entry by key/selector; most-specific wins, miss falls back to a default.',
            self::ComposeMany => 'Read composes an ORDERED chain — each entry transforms in turn; a broken link fails loud.',
            self::RunAll => 'Read engages EVERY entry — the whole enumerated list, order-significant.',
        };
    }
}
