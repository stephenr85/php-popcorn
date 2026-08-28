<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\Faceted;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * A single-axis faceted registry, modelled on `LensRegistry` — entries carry a tier that classifies
 * without shadowing, so a read by tier returns every match. Also `Gated`, to hold the point that the
 * optional capabilities compose.
 */
#[IsRegistry(
    root: 'schemas.lenses',
    of: 'test lenses, for the Faceted suite',
    arity: RegistryArity::RunAll,
)]
class FacetedRegistry implements Faceted, Gated
{
    public function facets(): array
    {
        return ['tier' => ['host-applied', 'engine-authoritative']];
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        return $this;
    }
}
