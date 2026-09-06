<?php

namespace Rushing\Popcorn\Registries;

/**
 * Whether an empty registry is expected. Independent of duplicate handling.
 *
 * Emptiness is runtime state. A required registry reports an unpopulated miss from `resolve()`;
 * diagnostic audits can also report it. Other reads retain their ordinary empty results.
 */
enum Optionality: string
{
    /**
     * Empty is a bug. `resolve()` against a registry with no entries throws
     * {@see Exceptions\RegistryMiss} with {@see Exceptions\MissReason::Unpopulated}, and a
     * NON-gating doctor audit reports the condition before anyone trips it in production.
     *
     * Emptiness is runtime state, so a static walk cannot see it and the boot-time surgeon gate
     * cannot either — a lazily-populated registry is legitimately empty until something fills it.
     * That is why enforcement is at read, and the audit is a report rather than a gate.
     */
    case Required = 'required';

    /**
     * Empty is normal, such as a registry of listeners with none registered.
     */
    case Optional = 'optional';
}
