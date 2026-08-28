<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Rushing\Popcorn\Registries\Faceted;

/**
 * `Faceted` without `Registry` — the position `Laddered`'s taxonomy sanctions for a ladder that reads
 * over registries it does not own (ticket 33 position 3, ticket 44 D0). No `#[IsRegistry]`, no root,
 * no keyspace. It also carries two axes, modelled on `CompositionProfile`'s `axis()`/`arity()` pair,
 * which is the case a flat value list could not express.
 */
class MultiAxisFacetedClassifier implements Faceted
{
    public function facets(): array
    {
        return [
            'axis' => ['harmonic', 'rhythmic'],
            'arity' => ['single', 'composite'],
        ];
    }
}
