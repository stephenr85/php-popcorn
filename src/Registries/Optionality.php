<?php

namespace Rushing\Popcorn\Registries;

/**
 * Is an EMPTY registry an error? A separate axis from `RegistryArity` (relocating here from
 * `laravel-beam` under ticket 21), which answers only "how many entries does a read engage".
 *
 * The split is OSGi's, and it is not a refinement — Declarative Services spells its reference
 * cardinality `optionality '..' multiplicity` and states flatly that **"the multiplicity is
 * irrelevant for the satisfaction of the reference"**. Spring is the counter-example: it requires
 * at least one matching element for a declared array/collection/map, *except* at constructors —
 * an inconsistency that exists precisely because the two axes were never named apart.
 *
 * Orthogonal to arity and to {@see OnDuplicate} in every combination; there are no meaningless
 * pairings to publish here (registry-kernel ticket 06, rule 4).
 */
enum Optionality: string
{
    /**
     * Empty is a bug. A read against a registry with no entries throws
     * {@see Exceptions\RegistryMiss} with {@see Exceptions\MissReason::Unpopulated}, and a
     * NON-gating doctor audit reports the condition before anyone trips it in production.
     *
     * Emptiness is runtime state, so a static walk cannot see it and the boot-time surgeon gate
     * cannot either — a lazily-populated registry is legitimately empty until something fills it.
     * That is why enforcement is at read, and the audit is a report rather than a gate.
     */
    case Required = 'required';

    /**
     * Empty is normal. A `RunAll` registry with no listeners is the common case, not a defect.
     */
    case Optional = 'optional';
}
