<?php

namespace Rushing\Popcorn\Registries;

/**
 * What `register()` does when the key is already taken.
 *
 * This is DECLARED per registry rather than fixed by the kernel, because the estate already ships
 * all three and each carries an argued reason — a kernel that silently picks one breaks the other
 * two. The three are not arbitrary; they encode what a duplicate *means* in that registry.
 *
 * Prior art puts the disambiguation rule at registration time too, everywhere it was surveyed:
 * Spring `@Primary`, OSGi `service.ranking`, systemd load-order. None of them made it a per-call
 * flag, and neither does this — the call site chooses only cardinality, by which method it calls.
 */
enum OnDuplicate: string
{
    /**
     * Last write wins; the displaced entry is recorded as `Superseded` and NEVER fed to a read.
     * The default, and the estate's majority: `InvocableRegistry` documents overwrite as the seam
     * where a host swaps a local default for a tenant's webhook, and `ParticleResourceRegistry`
     * relies on it so a re-scan is idempotent rather than duplicating.
     *
     * Because one live entry per exact key is guaranteed, ambiguity under this policy is reachable
     * only at a PREFIX key — `resolve('beam.resources')` where twelve children live.
     *
     * ## The replacement keeps the slot (registry-kernel ticket 62)
     *
     * A supersession is an **override in place**: the new entry lands where the displaced one sat, so a
     * list read (`matches()`, `keys()`, an owner's `all()`) returns the same order before and after.
     * Re-registering `operator` does not move `operator` to the end.
     *
     * This was DECIDED, not inherited. The kernel shipped the other reading first — displace, then
     * append — and a flagship realm test caught it: `['operator','tenant','site','user']` came back as
     * `['tenant','site','user','operator']` because a marker class re-declared the base realm. Three
     * arguments settled it:
     *
     * - **The name says replace.** `Supersede` is the case whose whole content is *"the entry at this
     *   key is now that one"*. Appending makes it a delete-plus-add, which is a different sentence, and
     *   one the enum has no case for.
     * - **A conforming migration is a re-plumbing.** The estate it replaces overrode by array
     *   assignment (`$this->items[$key] = $entry`), which preserves the slot. Ordering is not one of the
     *   differences the recipe declares (ticket 61 D6), and unlike the miss TYPE it is entirely
     *   avoidable — so the defect belonged in the kernel and was fixed once, not priced across ~65
     *   landed rows.
     * - **Append makes order depend on override history.** A host that swaps one shipped default for
     *   its own would silently re-sort a menu, a pipeline or a realm list it never touched. That is a
     *   surprise no registry declares and no caller can see coming.
     *
     * The cost is one field: {@see BasicRegistry} records `position` (the slot, inherited on
     * supersession) alongside `sequence` (identity and registration time). Registration order is still
     * the only ordering key — it is now unambiguously the order of FIRST registration.
     *
     * The other two cases are unaffected: `Reject` never replaces, and under `Admit` both entries live,
     * each in its own slot, in registration order.
     */
    case Supersede = 'supersede';

    /**
     * The duplicate is a collision bug; throw {@see Exceptions\DuplicateRegistryKey} at register
     * time, naming both registrants. `LensRegistry`'s case, in its own words: *"a silent
     * last-write-wins is how a registry of three lenses reports two, which is the class of
     * invisibility this exists to end."*
     *
     * Like `Supersede`, guarantees one entry per exact key, so ambiguity is prefix-only.
     */
    case Reject = 'reject';

    /**
     * Both entries live under the one key, and disambiguation is deferred to read time.
     *
     * Tower's capability layer is the reason this case exists: it *cannot* throw at write, because
     * *"hydration runs automatically on every tenant switch, so a duplicate slipping through must
     * never brick the whole overlay"* — so a duplicated alias lands as >1 claimant and the read
     * refuses it loudly as ambiguous. Deterministic refusal, never a silent pick, and never a
     * null-to-empty.
     *
     * ⚠️ That motivating shape is **not** a declaration of this policy, and this docblock used to say
     * it was (*"Tower's `CapabilityRegistry` is the exemplar"*). Checked live by registry-kernel
     * ticket 44: the alias pool and its ambiguity refusal live in
     * `Splicewire\Tower\Circuit\Capabilities\CapabilityLadder`, which declares no `#[IsRegistry]` at
     * all; tower's two capability registries both declare `Supersede`. The **declaring** exemplars are
     * `Splicewire\Beam\Rendering\ResourceRenderingRegistry` and
     * `Splicewire\Beam\Realm\RealmOverlayRegistry`. Cite those; the tower story is provenance, not an
     * instance.
     *
     * The only policy under which {@see Exceptions\MissReason::Ambiguous} can fire at an EXACT key
     * — and only then under `PickOne`, since under `ComposeMany`/`RunAll` several matches are the
     * answer rather than the error.
     */
    case Admit = 'admit';
}
