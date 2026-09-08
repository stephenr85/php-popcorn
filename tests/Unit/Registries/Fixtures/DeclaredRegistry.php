<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;

/**
 * A registry declaring itself the way the estate's will — the attribute on the OWNER, the store held
 * as a field. Deliberately declares non-default policies so a test can tell a read declaration from a
 * defaulted one.
 */
#[IsRegistry(
    root: 'beam.resources',
    entryType: 'string',
    onKeyDuplicate: OnKeyDuplicate::Reject,
    populationRequirement: PopulationRequirement::Required,
    description: 'test entries, for the contract suite',
)]
class DeclaredRegistry {}
