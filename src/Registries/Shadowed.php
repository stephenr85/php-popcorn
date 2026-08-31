<?php

namespace Rushing\Popcorn\Registries;

/**
 * One entry that went dark: a key a described registry holds, which a *nested* described registry owns.
 *
 * Recorded by {@see RegistryIndex::describe()} whenever the incoming root and an existing one overlap on
 * a live entry, and read back through {@see RegistryIndex::shadowed()}. Interleaved roots are legal and
 * route by longest prefix; what this records is the narrower case where one absolute key falls inside
 * two described registries, so `routeTo()` reaches the deeper one while `$shallower->has($key)` goes on
 * answering true. One key, two answers, depending on which door the caller entered.
 *
 * ## Why this is a record and not a throw
 *
 * It used to be `ShadowedRegistryKey`, thrown out of `describe()`. **Which registries a host describes,
 * and therefore whether two of them overlap, is a fact about the HOST** — the same package pair is a
 * collision at one install and not at another, depending on which providers loaded. The estate's rule is
 * that a check whose answer depends on the host is an advisory finding and not a boot failure, and the
 * throwing version had the shape that already stopped `~/Herd/tower` booting once, on a different check.
 *
 * This is the same trade registry-kernel ticket 34 made for duplicate ROOTS and ticket 48 landed: see
 * {@see Superseded}, whose shape this deliberately mirrors. Detectability is preserved and fatality is
 * traded — and here detectability is not merely preserved but strictly better, because the describe-time
 * check could only ever see entries that already existed at describe time. A registry is usually
 * described before its registrars fill it, so the post-boot reader
 * (`Splicewire\Beam\Doctor\RegistryConformanceAudit::shadowedEntries()`) sees a superset of what this
 * records, and it gates.
 *
 * ## What it carries, and why that and nothing else
 *
 * The two roots and the key answer *what went dark and between whom*; `$by` and `$sequence` answer *who
 * described the overlapping registry* and *in what order*, exactly as {@see Superseded} does — that is
 * what makes a report actionable rather than a bare fact. There is deliberately no `debug_backtrace()`,
 * on `Superseded`'s reasoning.
 *
 * The keys are {@see RegistryKey} objects rather than their renderings, on ticket 16's ruling: a foreign
 * key type cannot be reconstructed from its display form, and holding the string would make the record
 * lossy exactly where it is hardest to rebuild, and lossy silently.
 */
class Shadowed
{
    /**
     * @param  RegistryKey  $key  the entry that became unreachable through the index
     * @param  RegistryKey  $shallower  the root of the registry the entry is spelled under
     * @param  RegistryKey  $deeper  the root that took ownership of that address away
     * @param  string|null  $by  who described the registry whose arrival created the overlap; null where
     *                           the describe named nobody
     * @param  int  $sequence  monotonic per index, oldest first — the answer to "in what order"
     */
    public function __construct(
        public RegistryKey $key,
        public RegistryKey $shallower,
        public RegistryKey $deeper,
        public ?string $by,
        public int $sequence,
    ) {}
}
