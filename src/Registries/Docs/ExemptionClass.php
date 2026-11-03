<?php

namespace Rushing\Popcorn\Registries\Docs;

/**
 * Why a declared registry root carries no page (registry-docs ticket 01, amended ruling 4): the three
 * exemption classes, written into the manifest entry as its `class` field.
 *
 * ## The class is a field and is never derived from the declaration
 *
 * `#[IsRegistry]` has no slot that says "rebind-only" or "infrastructure". That is a fact about how
 * consumers use the registry, and nothing in the attribute, the entry type or the policies carries it.
 * Deriving it from the declaration would mean inventing a heuristic, such as "no registrant ever
 * registers here", and a heuristic is a guess. The author who writes the exemption already knows the
 * class, so the manifest states it and the parser refuses an entry without one (registry-docs ticket 04).
 */
enum ExemptionClass: string
{
    /** Kernel plumbing documented on a Foundations page rather than its own page. */
    case Infrastructure = 'infrastructure';

    /** A seam hosts rebind and nothing registers into. Its page would describe an empty registry. */
    case RebindOnly = 'rebind-only';

    /** A root a host app declares for itself. It is exempted in that host's own manifest. */
    case AppLocal = 'app-local';

    /**
     * Whether an exemption of this class must also name the doc that explains the mechanism.
     *
     * Required for infrastructure and rebind-only. An exemption without a pointer claims an absence
     * and gives the reader nowhere to check it.
     */
    public function requiresPointer(): bool
    {
        return $this !== self::AppLocal;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $class): string => $class->value, self::cases());
    }
}
