<?php

use Rushing\Popcorn\Registries\Faceted;
use Rushing\Popcorn\Registries\Filled;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\Laddered;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\FacetedRegistry;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\MultiAxisFacetedClassifier;

/**
 * The `Faceted` contract. It is a DECLARATIVE interface — the kernel never filters on it — so there is
 * no behaviour to assert. What these guard is the shape, which is the entire deliverable: that the
 * surface stays one method, that it stays a map rather than the flat list `Laddered::rungs()` returns,
 * and that it neither implies nor is implied by `Registry`.
 *
 * The erosion these exist to catch is a later reader "simplifying" `facets()` into a flat list of
 * names, or the kernel growing an `ofFacet()` that filters — both of which read as improvements and
 * both of which are the thing the docblock argues against.
 */
it('declares exactly one method, and it takes no arguments', function () {
    $interface = new ReflectionClass(Faceted::class);

    expect($interface->isInterface())->toBeTrue()
        ->and(array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            $interface->getMethods(),
        ))->toBe(['facets']);

    // No `ofFacet($axis, $value)`: the kernel does not filter, because the beneficiaries disagree on
    // what a facet read returns. Widening this surface is the decision the docblock forbids.
    expect($interface->getMethod('facets')->getNumberOfParameters())->toBe(0);
});

it('returns axes mapped to values, not the flat list Laddered returns', function () {
    $facets = (new FacetedRegistry)->facets();

    // Keyed by axis name — the noun the registry's own read uses. A flat `['host-applied', ...]` would
    // lose which axis a value belongs to, which is why this differs from `rungs()`.
    expect(array_keys($facets))->toBe(['tier'])
        ->and($facets['tier'])->toBe(['host-applied', 'engine-authoritative']);
});

it('permits more than one axis, which is why the return is a map', function () {
    // `CompositionProfile` carries both `axis()` and `arity()`; a ladder has exactly one axis by
    // construction and so needs no qualifier. This is the case a flat list could not express.
    $facets = (new MultiAxisFacetedClassifier)->facets();

    expect($facets)->toBe([
        'axis' => ['harmonic', 'rhythmic'],
        'arity' => ['single', 'composite'],
    ]);
});

it('does not imply Registry — it is a capability, like Laddered', function () {
    // Position 3 in ticket 33's taxonomy, sanctioned for `Laddered` by ticket 44 D0 and read by beam's
    // `UndescribedRegistryAudit` (ticket 57). A classifier that facets over registries it does not own
    // declares `Faceted` and nothing else: no root, no keyspace, none of `Registry`'s methods.
    expect(new MultiAxisFacetedClassifier)
        ->toBeInstanceOf(Faceted::class)
        ->not->toBeInstanceOf(Registry::class);

    expect((new ReflectionClass(Faceted::class))->getInterfaceNames())->toBe([]);
});

it('is unordered in both dimensions, so nothing shadows', function () {
    // The contract `Laddered` inverts: `rungs()` is outermost-first and the first rung shadows the
    // rest. Here neither axes nor values rank, so a consumer sorting either way must not change
    // meaning. Asserted as a property of the declaration rather than of any filtering the kernel does
    // — the kernel does none.
    $facets = (new FacetedRegistry)->facets();
    $reversed = array_map('array_reverse', $facets);

    foreach ($facets as $axis => $values) {
        expect(array_diff($values, $reversed[$axis]))->toBe([]);
    }

    // And the interface says nothing about order, unlike `Laddered`, whose `rungs()` docblock pins it
    // to `RegistryArity`'s outermost-first convention.
    $doc = (new ReflectionMethod(Faceted::class, 'facets'))->getDocComment();
    expect($doc)->toContain('Unordered');
    expect((new ReflectionMethod(Laddered::class, 'rungs'))->getDocComment())->toContain('outermost first');
});

it('composes with the other optional capabilities rather than replacing them', function () {
    // A faceted registry is free to also be gated or filled; the capabilities are orthogonal, and
    // arity is orthogonal too (the estate's carriers span RunAll, ComposeMany and PickOne).
    expect(new FacetedRegistry)
        ->toBeInstanceOf(Faceted::class)
        ->toBeInstanceOf(Gated::class)
        ->not->toBeInstanceOf(Filled::class)
        ->not->toBeInstanceOf(Laddered::class);
});
