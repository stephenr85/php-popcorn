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
     * Tower's `CapabilityRegistry` is the exemplar and the reason this case exists: it *cannot*
     * throw at write, because *"hydration runs automatically on every tenant switch, so a duplicate
     * slipping through must never brick the whole overlay"* — so a duplicated alias lands as >1
     * claimant and the read refuses it loudly as ambiguous. Deterministic refusal, never a silent
     * pick, and never a null-to-empty.
     *
     * The only policy under which {@see Exceptions\MissReason::Ambiguous} can fire at an EXACT key
     * — and only then under `PickOne`, since under `ComposeMany`/`RunAll` several matches are the
     * answer rather than the error.
     */
    case Admit = 'admit';
}
