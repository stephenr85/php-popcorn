<?php

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\BranchKey;
use Rushing\Popcorn\Registries\Exceptions\AmbiguousRegistryMatch;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\RegistryNode;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\NamespaceUriKey;

/**
 * The branch probe and the cross-registry child walk (registry-kernel ticket 46).
 *
 * Two verbs the keyspace was missing, and three consumers arrived at the first one independently
 * before it existed: ticket 23 flagged throw-on-ambiguity, ticket 30 found json-ns probing with
 * `has()`, ticket 32 found `descendants()` handing a membership question the wrong answer. What these
 * pin is the shape of the answers, and — as loudly — that `AmbiguousRegistryMatch` did NOT soften on
 * the way in: the probe is a way to ask a different question, not a lenient `resolve()`.
 */
function probeStore(string $root): BasicRegistry
{
    return new BasicRegistry(new IsRegistry(
        root: $root,
        of: 'test entries',
        arity: RegistryArity::PickOne,
        onDuplicate: OnDuplicate::Supersede,
    ));
}

// ---------------------------------------------------------------------------------------------
// The three states
// ---------------------------------------------------------------------------------------------

it('separates an entry from a derived branch from an absent key, without throwing on any', function () {
    $registry = probeStore('beam')
        ->register('realm.operator', 'the operator realm')
        ->register('particle.resources.invoices', 'the invoices resource');

    expect($registry->nodeAt('realm.operator'))->toBe(RegistryNode::Entry)
        // Registered at neither, derived from the leaves below both.
        ->and($registry->nodeAt('realm'))->toBe(RegistryNode::Branch)
        ->and($registry->nodeAt('particle.resources'))->toBe(RegistryNode::Branch)
        ->and($registry->nodeAt('realm.tenant'))->toBe(RegistryNode::Absent)
        // Segment-wise, like every other walk: `beam.realms` is not `beam.realm`.
        ->and($registry->nodeAt('realms'))->toBe(RegistryNode::Absent);
});

it('leaves AmbiguousRegistryMatch exactly where it was on the throwing reads', function () {
    $registry = probeStore('beam')->register('realm.operator', 'the operator realm');

    // The whole point of the probe is that this behaviour did not have to change. A caller that asked
    // for ONE thing and named a branch asked a question with several answers, and still hears so.
    expect(fn () => $registry->resolve('beam.realm'))->toThrow(AmbiguousRegistryMatch::class)
        ->and(fn () => $registry->tryResolve('beam.realm'))->toThrow(AmbiguousRegistryMatch::class)
        ->and($registry->nodeAt('beam.realm'))->toBe(RegistryNode::Branch)
        ->and($registry->has('beam.realm'))->toBeFalse();
});

it('reports Entry for an address that is also a branch, because that is what resolve() answers', function () {
    $registry = probeStore('beam')
        ->register('realm', 'the realm registry itself')
        ->register('realm.operator', 'the operator realm');

    // Deliberately not a fourth state: expandability is `children()`'s question, and folding it in
    // would make every caller destructure both to use either.
    expect($registry->nodeAt('realm'))->toBe(RegistryNode::Entry)
        ->and($registry->resolve('beam.realm'))->toBe('the realm registry itself')
        ->and($registry->children('beam.realm'))->toHaveCount(1);
});

it('reports Absent for a subtree the authorizer filters away, not Branch', function () {
    $registry = probeStore('beam')
        ->register('realm.operator', 'gated', ability: 'realm.read')
        ->register('particle.invoices', 'open');

    $registry->authorizeWith(new class implements Authorizer
    {
        public function allows(string $ability, RegistryKey $key): bool
        {
            return false;
        }
    });

    // Ticket 29 D6 at a single node: filtering a tree removes STRUCTURE, not just leaves. Reporting
    // Branch here would leak the existence of every gated subtree to every caller.
    expect($registry->nodeAt('realm'))->toBe(RegistryNode::Absent)
        ->and($registry->nodeAt('particle'))->toBe(RegistryNode::Branch);

    // …and the caller that must see the whole shape asks for it, taking the disclosure decision itself.
    expect($registry->unfiltered()->nodeAt('realm'))->toBe(RegistryNode::Branch);
});

it('answers for the root key, which the door stamps like any other', function () {
    $empty = probeStore('beam');
    $filled = probeStore('beam')->register('realm.operator', 'the operator realm');

    // Nobody has to find this out by calling it: the zero-segment key stamps to the declared root, so
    // it asks about the registry's own branch.
    expect($empty->nodeAt(Key::root()))->toBe(RegistryNode::Absent)
        ->and($filled->nodeAt(Key::root()))->toBe(RegistryNode::Branch)
        ->and($filled->nodeAt('beam'))->toBe(RegistryNode::Branch);
});

// ---------------------------------------------------------------------------------------------
// A foreign key has segments, so it has branches (ticket 11 / 16 D6: never prove this with `Key`)
// ---------------------------------------------------------------------------------------------

it('probes a foreign key type, where a pin is structurally a child of its stem', function () {
    $registry = probeStore('jsonns.namespaces');
    $pin = NamespaceUriKey::of('https://schemastud.dev/ns/grounding/2');
    $stem = NamespaceUriKey::of('https://schemastud.dev/ns/grounding');

    $registry->register($pin, 'the v2 handler');

    // The exact shape ticket 30 hit from the other end: the stem is a legal address holding nothing,
    // and until now the only non-throwing question about it was `has()`, whose false could not tell
    // "a version lives below here" from "nobody has ever mentioned this namespace".
    expect($registry->nodeAt($pin))->toBe(RegistryNode::Entry)
        ->and($registry->nodeAt($stem))->toBe(RegistryNode::Branch)
        ->and($registry->nodeAt(NamespaceUriKey::of('https://schemastud.dev/ns/screening')))
        ->toBe(RegistryNode::Absent);
});

// ---------------------------------------------------------------------------------------------
// descendants() over-returns for a membership question, and now says so in one call (ticket 32)
// ---------------------------------------------------------------------------------------------

it('lets a membership question filter the tree walk it was going to trust', function () {
    $registry = probeStore('beam')->register('realm.overlays.article', 'the article overlay');

    $walked = $registry->descendants('beam');

    // The ergonomic wrong answer: `beam.realm` and `beam.realm.overlays` are in this list, and both
    // are addresses `has()` denies and `resolve()` throws on.
    expect($walked)->toHaveCount(3)
        ->and(array_map('strval', $walked))
        ->toBe(['beam.realm', 'beam.realm.overlays', 'beam.realm.overlays.article']);

    $registered = array_values(array_filter(
        $walked,
        fn (RegistryKey $key) => $registry->nodeAt($key) === RegistryNode::Entry,
    ));

    expect(array_map('strval', $registered))->toBe(['beam.realm.overlays.article']);
});

// ---------------------------------------------------------------------------------------------
// The cross-registry walk: two altitudes on one index (ticket 26 D8)
// ---------------------------------------------------------------------------------------------

it('unions entry-children with child roots across the registry boundary', function () {
    $index = new RegistryIndex;
    $particle = probeStore('beam.particle')->register('fragments', 'the fragments resource');
    $ops = probeStore('beam.particle.fragments.ops')->register('download', 'the download op');

    $index->describe($particle)->describe($ops);

    // Ticket 26 D0's fixture, asked the question no verb could answer: `beam.particle.fragments.ops`
    // lives in a registry `beam.particle` does not own, and it is genuinely below this node.
    expect(array_map('strval', $index->childrenAcross('beam.particle.fragments')))
        ->toBe(['beam.particle.fragments.ops']);

    // The two altitudes, kept apart on purpose: the bare Nested verbs answer about the index's own
    // entries, which are REGISTRIES.
    expect($index->children('beam.particle.fragments'))->toHaveCount(1)
        ->and($particle->children('beam.particle.fragments'))->toBe([]);

    // And one level up, both halves contribute — the owning registry's own entry, and the branch the
    // deeper root derives.
    expect(array_map('strval', $index->childrenAcross('beam.particle')))
        ->toBe(['beam.particle.fragments']);
});

it('probes a node that only exists because a deeper root does', function () {
    $index = new RegistryIndex;
    $particle = probeStore('beam.particle')->register('fragments', 'the fragments resource');
    $index->describe($particle)->describe(probeStore('beam.particle.fragments.ops')->register('download', 'op'));

    expect($index->nodeAcross('beam.particle.fragments.ops'))->toBe(RegistryNode::Branch)
        ->and($index->nodeAcross('beam.particle.fragments.ops.download'))->toBe(RegistryNode::Entry)
        ->and($index->nodeAcross('beam.particle.fragments'))->toBe(RegistryNode::Entry)
        ->and($index->nodeAcross('beam.particle.nothing'))->toBe(RegistryNode::Absent);

    // The index's OWN altitude disagrees, and that is not a bug: `beam.particle.fragments` holds no
    // registry, and `beam.particle.fragments.ops.download` is not a root at all.
    expect($index->nodeAt('beam.particle.fragments'))->toBe(RegistryNode::Branch)
        ->and($index->nodeAt('beam.particle.fragments.ops.download'))->toBe(RegistryNode::Absent);
});

it('reads a described-but-empty registry as Absent in the keyspace, and as an entry in the index', function () {
    $index = new RegistryIndex;
    $empty = probeStore('beam.particle.resources');

    $index->describe($empty);

    // The verb reports the ENTRY keyspace, and an empty registry contributes no addresses to it.
    // Registry membership is a different question, and the index answers that one directly.
    expect($index->nodeAcross('beam.particle.resources'))->toBe(RegistryNode::Absent)
        ->and($index->nodeAt('beam.particle.resources'))->toBe(RegistryNode::Entry)
        ->and($index->owner('beam.particle.resources'))->toBe($empty);
});

it('hands back the owner key object where one exists, and a BranchKey only where none does', function () {
    $index = new RegistryIndex;
    $index->describe(probeStore('beam.particle')->register('fragments', 'the fragments resource'));
    $index->describe(probeStore('beam.particle.fragments.ops')->register('download', 'op'));

    $children = $index->childrenAcross('beam.particle');

    // `beam.particle.fragments` is a real entry in the shallower registry AND the address the deeper
    // root derives a branch at. The dedupe keeps the owner's own key, so a foreign key type would keep
    // its rendering rather than being replaced by a best-effort join.
    expect($children)->toHaveCount(1)
        ->and($children[0])->not->toBeInstanceOf(BranchKey::class);
});

it('walks the index root without the index answering as a registry for the keyspace', function () {
    $index = new RegistryIndex;
    $index->describe(probeStore('beam.realm')->register('operator', 'the operator realm'));

    // `routeTo(Key::root())` is the index itself (an exact hit on its own declared root), which is the
    // one routing target whose entries are registries rather than keyspace addresses. Letting it
    // answer here would report every root as though it were an entry.
    expect(array_map('strval', $index->childrenAcross(Key::root())))->toBe(['beam'])
        ->and($index->nodeAcross(Key::root()))->toBe(RegistryNode::Absent)
        ->and($index->nodeAcross('beam.realm.operator'))->toBe(RegistryNode::Entry);
});
