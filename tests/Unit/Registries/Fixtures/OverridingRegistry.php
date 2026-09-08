<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;

/**
 * The other half of *nearest wins*: a subclass taking its own branch of the keyspace by declaring one.
 * `Rushing\DataNav\NavInvocableRegistry` is the estate's live instance of this shape — the whole
 * subclass is the declaration, no method overridden.
 *
 * Every argument here differs from {@see DeclaredRegistry}'s, so a test cannot pass by reading a
 * half-merged declaration.
 */
#[IsRegistry(
    root: 'beam.overrides',
    entryType: 'int',
    onKeyDuplicate: OnKeyDuplicate::Admit,
    description: 'test entries, for the subclass-declares case',
)]
class OverridingRegistry extends DeclaredRegistry {}
