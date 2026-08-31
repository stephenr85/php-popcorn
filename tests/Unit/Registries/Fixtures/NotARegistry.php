<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * Declared and non-conforming — the shape `Schemastud\DataSchemas\Overlay\InMemoryOverlayRegistry` has
 * live in the estate. The bake believes the declaration; the index has to refuse it at hydration and
 * say which gate already owns the failure.
 */
#[IsRegistry(root: 'lazy.broken', of: 'nothing', arity: RegistryArity::PickOne)]
class NotARegistry {}
