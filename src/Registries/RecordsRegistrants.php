<?php

namespace Rushing\Popcorn\Registries;

/**
 * A registry that can be asked **who put an entry here** — the read side of the `$by` every
 * {@see Registry::register()} already writes.
 *
 * Like {@see RecordsSupersession}, this interface **exposes** a recording rather than switching one on.
 * `$by` has been written on every entry since ticket 06 D11; what did not exist until registry-kernel
 * ticket 48 was any way to read it back for a LIVE entry. `Registry`'s seven methods are `register`,
 * `has`, `resolve`, `tryResolve`, `matches`, `keys`, `unfiltered` — not one of them returns it. Ticket
 * 29 D2 needed `ReflectionProperty` to see the data at all, and {@see Superseded::$by} exposed it only
 * for the DEAD entry, never the live one.
 *
 * ## Why this is on an interface rather than on `Registry`
 *
 * Same reason {@see Filled}, {@see Gated}, {@see Nested} and {@see Forgettable} are: a registry that
 * does not keep per-entry registrants should not have to answer, and the type system should carry the
 * fact rather than an empty method body (ticket 07 D8, 08 D8). {@see ConfigRegistry} is the population
 * that makes this concrete — its store is a config array it does not own, and its docblock refuses to
 * *"fake uniformity by keeping a shadow ledger beside a store we do not own."* It legitimately does not
 * implement this, and callers can see that in the type.
 *
 * ## `registrant` is not `Registrar`, and the kernel already keeps them apart
 *
 * A {@see Registrar} is a thing that FILLS a registry. A **registrant** is the string naming who wrote
 * one entry. Both words were already in the kernel's vocabulary before this interface —
 * `Filled::registrars()` and `Forgettable::forgetBy(string $registrant)` — so this adds no new
 * distinction, only a second reader for one that exists. Do not merge them.
 *
 * ## `keysBy()` is `forgetBy()`'s missing twin, and it has a named call site
 *
 * The kernel already shipped the **destructive** filter by registrant and not the **read**. That
 * asymmetry is measurable: `Splicewire\Tower\Conduit\ConduitHydrator` maintains `$projectedNamed`, a
 * `capability => [providerKey, …]` ledger, **solely** so the next tenant switch can `forget()` its own
 * writes back out of a prism-plus registry with no per-tenant overlay layer. The sibling path needs no
 * ledger because `CapabilityRegistry::resetOverlay()` tracks its own tier — the asymmetry IS the
 * missing read (ticket 43). `keysBy()` is what lets that ledger be deleted.
 *
 * Unscoped, exactly like `forgetBy()`: **provenance is a selector for finding and explaining, never an
 * authorization.** Nothing here checks that the caller is the registrant, and a kernel that enforced
 * ownership on a read would be inventing a permission model it has no actor to evaluate (08 D8).
 *
 * ## Both reads FILTER, and that is not negotiable
 *
 * They pass through the host's {@see Authorizer} on the same argument every other actor-facing read
 * does: an unfiltered registrant read is an existence oracle that undoes the policy through a string.
 * A hidden entry answers `null` from `registrantOf()` and contributes no key to `keysBy()` — the same
 * shape `has()` gives, and byte-identical to genuinely absent. Tooling reads through
 * {@see Registry::unfiltered()}.
 *
 * ## What this deliberately does NOT do
 *
 * **It does not fix what `$by` SAYS.** Ticket 29 D2 measured the live content and it is near-degenerate:
 * 38 entries, **13 carry `$by`**, and of 10 distinct registrants **8 are the registering registry's own
 * class**, plus `extend` ×3 from graphine's `Manager` delegation. Nothing today names a *package* or a
 * *provider*. Setting that vocabulary is tickets 37 and 38's as they migrate, and until they do, a
 * discriminator built over this field would be reading a tautology. `null` is legal and stays legal —
 * the majority case, today.
 */
interface RecordsRegistrants
{
    /**
     * Who registered the live, visible entry at exactly `$key` — or `null` where nothing is there, the
     * entry is hidden from this caller, or the write named nobody.
     *
     * **Three causes, one answer, deliberately.** Distinguishing them would reintroduce the existence
     * oracle `has()`'s filtering exists to close, and it is the same collapse
     * {@see Exceptions\MissReason::Filtered} already makes byte-identical to `Absent`.
     */
    public function registrantOf(RegistryKey|string $key): ?string;

    /**
     * Every visible key registered BY `$registrant`, in registration order — the read twin of
     * {@see Forgettable::forgetBy()}.
     *
     * Exact string match, not a prefix or a glob. A registrant vocabulary that wants hierarchy should
     * say so in tickets 37/38 rather than have the kernel guess at one, and matching loosely here
     * would make `keysBy()` and `forgetBy()` disagree about what they select — which, given one of them
     * deletes, is the worst possible place for a near-miss.
     *
     * @return list<RegistryKey>
     */
    public function keysBy(string $registrant): array;
}
