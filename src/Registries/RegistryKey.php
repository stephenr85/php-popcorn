<?php

namespace Rushing\Popcorn\Registries;

use Stringable;

/**
 * The address of an entry in a registry: an ordered list of canonical segments, written `a.b.c`.
 *
 * An interface rather than a concrete type so a consumer's own domain key (an app's `ResourceKey`,
 * say) can BE a registry key without a wrapper. {@see Key} is the canonical implementation and the
 * only thing that parses — see its class docblock for why parsing is deliberately not on this
 * interface, and not on the registry either.
 *
 * Equality is defined on {@see segments()}, NEVER on the source string (ticket 01).
 */
interface RegistryKey extends Stringable
{
    /** @return list<string> the canonical segments, outermost first */
    public function segments(): array;

    public function equals(RegistryKey $other): bool;
}
