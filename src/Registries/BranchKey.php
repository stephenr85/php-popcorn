<?php

namespace Rushing\Popcorn\Registries;

/**
 * A {@see RegistryKey} the kernel DERIVED from segments rather than one anybody registered.
 *
 * It exists for exactly one job: {@see Nested::children()} and {@see Nested::descendants()} report
 * branch addresses that hold no entry of their own — `a.b.c` makes `a.b` a child of `a` whether or not
 * anything is registered at `a.b`. Where a real entry sits at the address, those methods return the
 * registrant's own key object instead, so the owner's rendering is preserved.
 *
 * ## Why it does not parse, and why it is not a `Key`
 *
 * {@see Key} validates its charset because it is what a *string literal* in source or config becomes.
 * A branch address is not written by anyone — it is computed by slicing segments that a foreign key
 * type supplied, and those segments are opaque to the kernel (ticket 05's amendment). Running them back
 * through `Key::parse()` is the round-trip that made `children()` throw on any non-`Key` key.
 *
 * So this constructs, never parses. `__toString()` joins with {@see Key::SEPARATOR} as a best-effort
 * rendering — honest for canonical segments, approximate for foreign ones, and never used as identity:
 * equality is on {@see segments()}, as it is for every `RegistryKey`.
 */
class BranchKey implements RegistryKey
{
    /** @param  list<string>  $segments */
    public function __construct(private array $segments) {}

    /** @return list<string> */
    public function segments(): array
    {
        return $this->segments;
    }

    public function equals(RegistryKey $other): bool
    {
        return $this->segments === $other->segments();
    }

    public function __toString(): string
    {
        return implode(Key::SEPARATOR, $this->segments);
    }
}
