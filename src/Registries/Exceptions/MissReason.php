<?php

namespace Rushing\Popcorn\Registries\Exceptions;

/**
 * Why a read did not produce one entry. Carried on {@see RegistryMiss} so a miss is diagnosable
 * rather than a bare null — Tower's `CapabilityResolutionReason` is the shipped exemplar this is
 * extracted from, minus its four Conduit-domain cases.
 *
 * Note what is NOT here. A malformed key is {@see InvalidRegistryKey}, thrown at parse time: an
 * unparseable key is a declaration error, not a miss, and folding the two would merge failures with
 * different fixes. A duplicate at write is {@see DuplicateRegistryKey}. Nothing was missed in
 * either case.
 */
enum MissReason: string
{
    /** No entry under that key. The ordinary miss. */
    case Absent = 'absent';

    /**
     * Entries exist but no single one answers — resolving would be a coin-flip.
     *
     * Reachable two ways, and they are different bugs: at an EXACT key only under
     * {@see \Rushing\Popcorn\Registries\OnDuplicate::Admit}, and at a PREFIX key under any policy,
     * when `resolve()` names a branch rather than a leaf. Only ever under `PickOne`.
     */
    case Ambiguous = 'ambiguous';

    /**
     * The registry declares itself `Optionality::Required` and is empty — nobody registered
     * anything. Deliberately distinct from `Absent`: "your key isn't in here" and "there is no
     * *here* yet" have different causes and different fixes, and collapsing them is how a missing
     * service provider gets misread as a typo.
     */
    case Unpopulated = 'unpopulated';

    /**
     * An entry matched but a visibility predicate hid it from this caller.
     *
     * **Carried, never rendered.** The message and any HTTP shape are `Absent`, because reporting
     * this truthfully tells a caller that a key they may not read nevertheless exists. The true
     * reason stays available to the doctor and to logs. Registry-kernel ticket 09 owns the
     * predicate seam and confirms this leak policy; it is recorded here so the case is not
     * invented twice.
     */
    case Filtered = 'filtered';
}
