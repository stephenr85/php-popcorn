<?php

use Rushing\Popcorn\Registries\Exceptions\AmbiguousRegistryMatch;
use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Rushing\Popcorn\Registries\Exceptions\MissReason;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\Key;

/**
 * The miss family's contract (registry-kernel ticket 06). These assert the decisions that are easy
 * to erode later — the absent/ambiguous subclass relationship, the provenance in the messages, and
 * the deliberate indistinguishability of a filtered miss from an absent one.
 */
it('reports an ordinary miss as absent and carries no candidates', function () {
    $miss = RegistryMiss::absent(Key::parse('beam.resources.order'));

    expect($miss->reason)->toBe(MissReason::Absent)
        ->and($miss->key)->toBe('beam.resources.order')
        ->and($miss->candidates)->toBe([])
        ->and($miss->getMessage())->toContain('beam.resources.order');
});

it('models ambiguity as a SUBCLASS of a miss, so either granularity is catchable', function () {
    $miss = RegistryMiss::ambiguous('beam.resources', [
        ['key' => 'beam.resources.order', 'by' => 'splicewire/laravel-beam'],
        ['key' => 'beam.resources.invoice', 'by' => 'rushing/laravel-commerce'],
    ]);

    expect($miss)->toBeInstanceOf(AmbiguousRegistryMatch::class)
        ->and($miss)->toBeInstanceOf(RegistryMiss::class)
        ->and($miss->reason)->toBe(MissReason::Ambiguous);
});

it('names every candidate AND its registrant, because that is what makes the message actionable', function () {
    $message = RegistryMiss::ambiguous('beam.resources', [
        ['key' => 'beam.resources.order', 'by' => 'splicewire/laravel-beam'],
        ['key' => 'beam.resources.invoice', 'by' => null],
    ])->getMessage();

    expect($message)->toContain('2 candidates')
        ->and($message)->toContain('`beam.resources.order` (by splicewire/laravel-beam)')
        ->and($message)->toContain('`beam.resources.invoice`')
        ->and($message)->not->toContain('by )');
});

it('distinguishes an empty Required registry from a bad key, since the fixes differ', function () {
    $miss = RegistryMiss::unpopulated('beam.resources.order', 'beam.resources');

    expect($miss->reason)->toBe(MissReason::Unpopulated)
        ->and($miss->getMessage())->toContain('beam.resources')
        ->and($miss->getMessage())->toContain('service provider');
});

it('renders a filtered miss identically to an absent one, leaking nothing but the reason', function () {
    $filtered = RegistryMiss::filtered('beam.resources.payroll');
    $absent = RegistryMiss::absent('beam.resources.payroll');

    expect($filtered->getMessage())->toBe($absent->getMessage())
        ->and($filtered->candidates)->toBe([])
        ->and($filtered->reason)->toBe(MissReason::Filtered);
});

it('refuses a duplicate at WRITE time and is not a miss', function () {
    $refusal = DuplicateRegistryKey::for('beam.lens.redact', 'rushing/laravel-commerce', 'splicewire/laravel-beam');

    expect($refusal)->not->toBeInstanceOf(RegistryMiss::class)
        ->and($refusal->getMessage())->toContain('by splicewire/laravel-beam')
        ->and($refusal->getMessage())->toContain('from rushing/laravel-commerce');
});
