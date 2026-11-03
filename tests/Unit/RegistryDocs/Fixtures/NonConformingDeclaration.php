<?php

namespace Rushing\Popcorn\Tests\Unit\RegistryDocs\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;

/** Registry-docs fixture: declared, but not a `Registry`. The bake leaves it out, and so does the docs population. */
#[IsRegistry(root: 'docs.fixture.nonconforming')]
class NonConformingDeclaration {}
