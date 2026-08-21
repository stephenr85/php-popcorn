<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * A registry declaring itself the way the estate's will — the attribute on the OWNER, the store held
 * as a field. Deliberately declares non-default policies so a test can tell a read declaration from a
 * defaulted one.
 */
#[IsRegistry(
    root: 'beam.resources',
    of: 'test entries, for the contract suite',
    arity: RegistryArity::RunAll,
    entryType: 'string',
    onDuplicate: OnDuplicate::Reject,
    optionality: Optionality::Required,
)]
class DeclaredRegistry {}
