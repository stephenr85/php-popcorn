<?php

namespace Rushing\Popcorn\Tests\Unit\RegistryDocs\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registry;

/** Registry-docs fixture that declares the empty root, as only the index does. It never enters the population. */
#[IsRegistry(root: '', entryType: Registry::class)]
class WholeTreeRegistry extends DocsFixtureRegistry {}
