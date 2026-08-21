<?php

namespace Rushing\Popcorn\Registries;

/**
 * A registry whose entries can be taken back out.
 *
 * Optional beside `Filled` and {@see Nested}, and the optionality is the design: a registry that
 * cannot be torn down simply does not implement this, and the type system carries the fact rather than
 * a docblock asking you not to call something (registry-kernel ticket 08 D8).
 *
 * ## This is production machinery, not a test convenience
 *
 * The estate ships five removal verbs already, and the live justification is a tenant overlay, not a
 * suite: `php-popcorn`'s own `InvocableRegistry::forget()` is documented as the teardown half of a
 * per-tenant projection — *"so nothing bleeds across tenants on a shared worker"* — and tower's
 * `ConduitHydrator` calls `PrismPlusManager::forget()` on every tenant switch. Ticket 11's reduction
 * proof fails on contact without this interface.
 *
 * ## What is deliberately absent
 *
 * **No `clear()`, `reset()` or `flush()`** (08 D12). The live wholesale cases are two, both in test
 * support; a keyed removal and a registrant-keyed removal cover every production one.
 *
 * **No removal ledger, no tombstone, no trace.** A tombstone is not a kernel feature — it is an
 * ordinary entry whose VALUE refuses, and tower ships both variants argued in their own docblocks (a
 * disabled conduit resolves and refuses at invoke; a disabled corpus source is *"simply absent from the
 * union, not a tombstone"*). That is correct application, not a gap here.
 */
interface Forgettable
{
    /**
     * Remove the entry at `$key`. A no-op if absent.
     *
     * **Destructive and total: it clears the entry AND its supersession record.** The key returns to
     * virgin state. This is not tidiness — a surviving `Superseded` record for a tenant-projected entry
     * IS the cross-tenant leak the teardown exists to prevent, so a `forget()` that kept history would
     * defeat its own reason for existing (08 D9).
     */
    public function forget(RegistryKey|string $key): static;

    /**
     * Remove every entry whose registrant is `$registrant` — the provider-scale unwind, and the shape
     * `BeamExtensionInstallManifest::forget($package)` already ships.
     *
     * **Unscoped, like {@see forget()}.** Provenance is a selector for finding and explaining, never an
     * authorization: nothing here checks that the caller is the registrant. A kernel that enforced
     * ownership on removal would be inventing a permission model it has no actor to evaluate (08 D8).
     */
    public function forgetBy(string $registrant): static;
}
