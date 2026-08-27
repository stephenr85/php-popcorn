<?php

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;
use Rushing\Popcorn\Registries\ClassKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * A class name as a key, carrying its NAMESPACE.
 *
 * {@see Key::fromClass()} already makes class-as-key kernel vocabulary, but it takes the basename
 * only — its own docblock says `Splicewire\Beam\Realm\RealmRegistry` becomes the single segment
 * `realm-registry`. Measured across the splicewire estate on 2026-08-27: **487 distinct `*Data` class
 * basenames, 17 of which name more than one class** (34 classes; `SyncData`, `ThreadData`, `UserData`
 * and `PlanData` among them). Under {@see OnDuplicate::Supersede} those collide SILENTLY — the second
 * registrant wins and `superseded()` records it as a legitimate override rather than an accident.
 *
 * This type makes that collision unrepresentable instead of merely unlikely. It is a CONSTRUCTOR, not
 * a parser: {@see Key} refuses folding on purpose ("no case folding, no separator unification, no
 * trimming, no alias resolution") so the runtimes cannot disagree, and a normalising parser beside
 * `tryParse()` would be ambient — any string-holder could invoke it, and one input would address
 * different entries depending on which door it came through.
 */
function classKeyRegistry(): BasicRegistry
{
    return new BasicRegistry(new IsRegistry(
        root: 'schemas.fixtures',
        of: 'fixtures keyed by declaring class',
        arity: RegistryArity::PickOne,
        onDuplicate: OnDuplicate::Supersede,
        optionality: Optionality::Optional,
    ));
}

it('renders the full namespace as kebab-cased segments', function () {
    expect((string) ClassKey::of('Splicewire\Beam\Commerce\Data\PlanEditData'))
        ->toBe('splicewire.beam.commerce.data.plan-edit-data');
});

it('exposes segments, which is what equality compares', function () {
    expect(ClassKey::of('Splicewire\Beam\Commerce\Data\PlanEditData')->segments())
        ->toBe(['splicewire', 'beam', 'commerce', 'data', 'plan-edit-data']);
});

it('tolerates a leading separator, since ::class never has one but a hand-written string might', function () {
    expect(ClassKey::of('\Splicewire\Tower\Data\ThreadData')->equals(ClassKey::of('Splicewire\Tower\Data\ThreadData')))
        ->toBeTrue();
});

/** The motivating case, stated against the thing it improves on rather than in isolation. */
it('does not collide where Key::fromClass does', function () {
    $tower = 'Splicewire\Tower\Data\ThreadData';
    $beam = 'Splicewire\Beam\Threads\Data\ThreadData';

    expect(Key::fromClass($tower)->equals(Key::fromClass($beam)))->toBeTrue()
        ->and(ClassKey::of($tower)->equals(ClassKey::of($beam)))->toBeFalse();
});

it('registers and resolves through a registry that would reject the raw class name', function () {
    $key = ClassKey::of('Splicewire\Beam\Commerce\Data\PlanEditData');

    expect(fn () => Key::parse('Splicewire\Beam\Commerce\Data\PlanEditData'))->toThrow(InvalidRegistryKey::class);

    $registry = classKeyRegistry()->register($key, 'the-fixture', by: 'schemastud/laravel-data-schemas');

    expect($registry->has($key))->toBeTrue()
        ->and($registry->resolve($key))->toBe('the-fixture');
});

it('keeps two same-basename classes as distinct entries in one registry', function () {
    $registry = classKeyRegistry()
        ->register(ClassKey::of('Splicewire\Tower\Data\ThreadData'), 'tower', by: 'tower')
        ->register(ClassKey::of('Splicewire\Beam\Threads\Data\ThreadData'), 'beam', by: 'beam');

    expect($registry->resolve(ClassKey::of('Splicewire\Tower\Data\ThreadData')))->toBe('tower')
        ->and($registry->resolve(ClassKey::of('Splicewire\Beam\Threads\Data\ThreadData')))->toBe('beam');
});

it('refuses a class whose name cannot yield a legal segment, rather than folding it', function () {
    expect(fn () => ClassKey::of('Vendor\Some Class'))
        ->toThrow(InvalidArgumentException::class);
});
