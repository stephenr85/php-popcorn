<?php

namespace Rushing\Popcorn\Tests\Unit\RegistryDocs\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;

/** Registry-docs fixture declared with every default. */
#[IsRegistry(root: 'docs.fixture.delta')]
class DeltaRegistry extends DocsFixtureRegistry {}
