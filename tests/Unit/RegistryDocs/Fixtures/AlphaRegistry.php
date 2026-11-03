<?php

namespace Rushing\Popcorn\Tests\Unit\RegistryDocs\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;

/** Registry-docs fixture with non-default policies, so a facts block can drift on each of them. */
#[IsRegistry(root: 'docs.fixture.alpha', entryType: 'string', onKeyDuplicate: OnKeyDuplicate::Reject, populationRequirement: PopulationRequirement::Required, description: 'authorial prose, never a fact', order: 3)]
class AlphaRegistry extends DocsFixtureRegistry {}
