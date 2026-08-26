<?php

namespace Rushing\Popcorn\Registries;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\Exceptions\UnregisteredRegistry;

/**
 * The registry OF registries: every registry in the estate describes itself into this, and every key
 * routes through it to the one registry whose declared root owns it.
 *
 * It is the successor to `splicewire/laravel-beam`'s `ManifestIndex`, and it keeps that class's one
 * load-bearing property — **the direction**. An owner registers DOWN into the index from its OWN
 * provider's `boot()`; the index never reaches up, never scans, never discovers, and never learns a
 * consumer's name except by being told it. Nothing in the traversal below asks who a consumer is; a
 * registry appears here because it described itself, and for no other reason (registry-kernel ticket 20,
 * question 2).
 *
 * ## It is a registry, under its own contract, with no exemption
 *
 * The index self-hosts: it describes itself at construction, so `resolve(Key::root())` is the index and
 * its declared `Optionality::Required` is true by construction rather than aspirational.
 *
 * Its declared root is the **zero-segment key** — the root of the whole tree, {@see Key::root()} — and
 * that is what lets self-hosting be honest instead of a special case. Every other registry stamps its
 * root onto incoming keys; the index owns the entire keyspace, so stamping is a no-op and the rule
 * applies to it unchanged. The rejected alternatives were a root of `popcorn.registries` plus a stamping
 * exemption (a special case in the one place the package can least afford one), and the same root with
 * entries genuinely stamped, which would prefix every registry's address with a meaningless segment and
 * make `beam.particle.resources` unaddressable under its own name.
 *
 * ## Entries are LIVE registries, and the index constructs none of them
 *
 * `describe()` takes an object that already exists. The owner built it in its own `boot()` — the
 * register-down rule again — so `keys()` walks a list of things already in memory and instantiates
 * nothing. Holding class-strings and resolving them lazily would invert the direction: the index would
 * become the thing that decides when a consumer's registry comes into being.
 *
 * ## Two questions, two verbs
 *
 * {@see routeTo()} answers "which registry owns this key" and returns the STORE, because that is what a
 * read is handed to. {@see owner()} answers "what object did the owner describe" and returns the
 * owner's own class where there is one — `ParticleResourceRegistry` with its caller-facing sugar, not
 * the `BasicRegistry` inside it. Callers reaching for a registry by name want the second; routing wants
 * the first.
 *
 * The index is itself a registry — of registries — so its generic is bound rather than declared:
 * `Registry<Registry<mixed>>`, the type-system half of the `entryType: Registry::class` immediately
 * below. The inner registries' own entry types are theirs to declare; the index does not know them and
 * has no business claiming to.
 *
 * @implements Registry<Registry<mixed>>
 */
#[IsRegistry(
    root: '',
    of: 'every registry in the estate, keyed by the root of the keyspace it owns',
    arity: RegistryArity::PickOne,
    entryType: Registry::class,
    onDuplicate: OnDuplicate::Reject,
    optionality: Optionality::Required,
    note: 'Roots must be unique: two registries claiming one root make that branch unroutable, so a '
        .'duplicate is refused at describe time rather than recorded as a supersession.',
    order: 0,
)]
class RegistryIndex implements Forgettable, Gated, Nested, Registry
{
    /** @var BasicRegistry<Registry<mixed>> */
    private BasicRegistry $registries;

    /** @var array<string, object> owner objects, keyed by their registry's rendered root */
    private array $owners = [];

    private ?Authorizer $authorizer = null;

    public function __construct()
    {
        $this->registries = BasicRegistry::for($this);

        $this->describe($this, $this);
    }

    /**
     * Take a registry into the index, keyed by the root it declares.
     *
     * `$store` is what routing hands reads to; `$owner` is the object the estate calls "the registry" —
     * the class carrying the `#[IsRegistry]` declaration and whatever caller-facing sugar it exposes.
     * They differ under the sanctioned composition pattern, where the owner HOLDS a {@see BasicRegistry}
     * rather than implementing {@see Registry} itself (ticket 01 D1), and the one-line call in the
     * owner's `boot()` is `$index->describe($this->entries, $this)`.
     *
     * Requiring the owner to implement `Registry` instead — so one argument would do — was rejected
     * precisely because it would make method-for-method delegation mandatory and quietly withdraw that
     * sanction.
     *
     * `$by` names the REGISTRANT, and it defaults to the owner's (or store's) class — which is what a
     * package describing its own registry wants. A caller describing on a narrower scale passes its own
     * selector instead, so {@see forgetBy()} can unwind that scale in one call: the live shape is a
     * tenant or conduit id, where `describe(…, by: $tenantId)` and `forgetBy($tenantId)` bracket a
     * hydration. Provenance is a selector for finding and explaining, never an authorization — nothing
     * here checks that the caller is the registrant (ticket 08 D8, ticket 41 D8).
     *
     * @template TStored
     *
     * @param  Registry<TStored>  $store
     *
     * @throws InvalidArgumentException the store can say what root it owns
     * @throws Exceptions\DuplicateRegistryKey another registry already claims that root
     * @throws Exceptions\ShadowedRegistryKey the new root would make an existing entry unreadable
     */
    public function describe(Registry $store, ?object $owner = null, ?string $by = null): static
    {
        $declaration = $this->declarationOf($store, $owner);
        $root = $declaration->rootKey();

        $this->assertUnshadowed($store, $root);

        $this->registries->register(
            $root,
            $store,
            by: $by ?? ($owner === null ? $store::class : $owner::class),
        );

        if ($owner !== null) {
            $this->owners[(string) $root] = $owner;
        }

        // Both edges of the push, deliberately — see Gated. Stamping only on installation would leave a
        // registry described afterwards unfiltered; stamping only here would leave one described before
        // the host's provider ran unfiltered. Neither package controls that ordering.
        $this->push($store);

        return $this;
    }

    /**
     * Refuse a root that would make an already-registered entry unreadable (ticket 26 D5).
     *
     * Interleaved roots are LEGAL — {@see routeTo()} handles nesting by construction. What is refused is
     * the narrower case where one absolute key falls inside two described registries, because then the
     * answer depends on which door the caller entered. Both directions are checked, since a registry may
     * be described before or after the one it nests with:
     *
     *  - the incoming root sits under an existing registry that already holds a key at or below it;
     *  - an existing root sits under the incoming registry, which already holds a key at or below THAT.
     *
     * **The self-hosting entry is never a party to this.** The index's root is zero-segment, i.e. a
     * prefix of every key in the estate, and its "entries" are roots rather than entry keys — checking it
     * would refuse every registry there is, on a category error.
     *
     * ## The window this leaves, deliberately
     *
     * A registry is often described before its registrars fill it, so the entry that will collide may not
     * exist yet at describe time. Closing that would mean `BasicRegistry::register()` consulting the
     * index on every write, which inverts a dependency the kernel keeps one-way — the store knows nothing
     * about the index, and that is what lets a registry be used without one. The residual window is
     * carried by the beam-side conformance audit instead (ticket 49), which reads the live index after
     * boot and sees exactly the state this check cannot.
     */
    /**
     * @template TStored
     *
     * @param  Registry<TStored>  $incoming
     */
    private function assertUnshadowed(Registry $incoming, RegistryKey $root): void
    {
        $described = $this->registries->unfiltered();

        foreach ($described->keys() as $existingRoot) {
            if ($existingRoot->segments() === []) {
                continue;
            }

            $existing = $described->resolve($existingRoot);

            // No `instanceof Registry` guard: the index is declared `Registry<Registry<mixed>>`, so
            // the type system now proves what that check used to assert at runtime. PHPStan said so
            // (`instanceof.alwaysTrue`) the moment the generic landed — one dead branch retired by an
            // annotation, which is the generic paying for itself inside the kernel before a consumer
            // ever reads it.
            if ($existing === $this) {
                continue;
            }

            if ($this->isUnder($root, $existingRoot->segments())) {
                $this->refuseShadowed($existing, $root, $existingRoot, $root);
            }

            if ($this->isUnder($existingRoot, $root->segments())) {
                $this->refuseShadowed($incoming, $existingRoot, $root, $existingRoot);
            }
        }
    }

    /**
     * Look for a key in `$holder` at or below `$boundary`, and throw naming it if one is there.
     *
     * `$shallower` and `$deeper` are the two roots as the message needs them, rather than being re-derived
     * here: the caller already knows which of the pair is the outer one and passing it beats guessing.
     */
    /**
     * @template TStored
     *
     * @param  Registry<TStored>  $holder
     */
    private function refuseShadowed(
        Registry $holder,
        RegistryKey $boundary,
        RegistryKey $shallower,
        RegistryKey $deeper,
    ): void {
        foreach ($holder->unfiltered()->keys() as $key) {
            if ($key->equals($boundary) || $this->isUnder($key, $boundary->segments())) {
                throw Exceptions\ShadowedRegistryKey::for($key, $shallower, $deeper);
            }
        }
    }

    /**
     * The registry whose declared root is the longest prefix of `$key`, or null when none claims it.
     *
     * The walk is segment-wise throughout — `beam.realms` is not under `beam.realm` however much the
     * strings suggest otherwise — and it reads the index UNFILTERED, because routing is a structural
     * question about the keyspace rather than an actor-facing read. The authorization filter then applies
     * inside the registry the key was routed to, which is where the entry actually lives.
     *
     * **The index's own zero-segment root is not a routing candidate**, except on an exact match. It is
     * a prefix of literally every key, so admitting it to the walk would mean no key could ever fail to
     * route: every miss would silently come back as "the index owns it" and hand a caller a registry
     * where it asked for an entry. `routeTo(Key::root())` still returns the index, because that is an
     * exact hit on a real declared root rather than a fallback.
     */
    /** @return Registry<mixed>|null */
    public function routeTo(RegistryKey|string $key): ?Registry
    {
        $key = Key::of($key);
        $unfiltered = $this->registries->unfiltered();

        $best = null;
        $depth = -1;

        foreach ($unfiltered->keys() as $root) {
            $prefix = $root->segments();

            if (! $key->equals($root) && ($prefix === [] || ! $this->isUnder($key, $prefix))) {
                continue;
            }

            if (count($prefix) > $depth) {
                $best = $root;
                $depth = count($prefix);
            }
        }

        return $best === null ? null : $unfiltered->resolve($best);
    }

    /**
     * Strictly-below, segment-wise.
     *
     * Open-coded rather than delegated to {@see Key::isUnder()} because the key being routed may be a
     * foreign {@see RegistryKey} — the interface guarantees `segments()` and `equals()` and nothing more,
     * and the kernel compares segments rather than requiring an implementation to grow a method.
     *
     * @param  list<string>  $prefix
     */
    private function isUnder(RegistryKey $key, array $prefix): bool
    {
        $segments = $key->segments();

        if (count($prefix) >= count($segments)) {
            return false;
        }

        return array_slice($segments, 0, count($prefix)) === $prefix;
    }

    /**
     * The same walk, throwing where nothing claims the key.
     *
     * This is what `Popcorn::pop()` calls, and {@see UnregisteredRegistry} is deliberately NOT a
     * {@see Exceptions\RegistryMiss}: a key naming no registry and a key naming a registry with nothing
     * at it are different operator errors with different fixes.
     *
     * @return Registry<mixed>
     *
     * @throws UnregisteredRegistry
     */
    public function ownerOf(RegistryKey|string $key): Registry
    {
        return $this->routeTo($key) ?? throw UnregisteredRegistry::for(
            $key,
            array_map('strval', $this->registries->unfiltered()->keys()),
        );
    }

    /**
     * The object described at exactly `$root` — the owner where one was named, otherwise the store.
     *
     * This is what a caller reaching for a registry BY NAME wants: the class with the domain sugar on it,
     * not the storage it delegates to.
     */
    public function owner(RegistryKey|string $root): ?object
    {
        $root = Key::of($root);

        return $this->owners[(string) $root] ?? $this->registries->tryResolve($root);
    }

    /**
     * The declaration of the registry described at exactly `$root`, or `null` when nothing is described
     * there.
     *
     * Read from the LIVE registry rather than from the owner's class attribute, and that is the whole
     * point of it: {@see BasicRegistry::__construct()} takes an {@see IsRegistry} as an instance field, so
     * a registry whose root is computed at boot declares itself completely without any class ever
     * carrying the attribute (ticket 26 D2). A reader that reflects the owner's class sees nothing there
     * and concludes — wrongly — that the registry is undeclared.
     *
     * It delegates to the same {@see declarationOf()} `describe()` used, deliberately: a second copy of
     * that resolution is how a tool comes to disagree with the index it is reporting on (ticket 49).
     */
    public function declarationAt(RegistryKey|string $root): ?IsRegistry
    {
        $root = Key::of($root);
        $store = $this->registries->unfiltered()->tryResolve($root);

        if (! $store instanceof Registry) {
            return null;
        }

        return $this->declarationOf($store, $this->owners[(string) $root] ?? null);
    }

    /**
     * Install the host's authorizer: onto the index, into every registry it already holds, and — via
     * {@see describe()} — into every registry described after this call.
     *
     * Exactly one authorizer exists and it lives here (ticket 09 D7). Because the registries it pushes
     * into are the same container singletons a consumer injects directly, a registry reached WITHOUT the
     * index is filtered too — which is what makes "one authorizer" a guarantee rather than a convention
     * that a registry can forget to follow.
     */
    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->authorizer = $authorizer;
        $this->registries->authorizeWith($authorizer);

        foreach ($this->registries->unfiltered()->matches(Key::root()) as $store) {
            if ($store !== $this) {
                $this->push($store);
            }
        }

        return $this;
    }

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $this->registries->register($key, $entry, $by, $ability);

        return $this;
    }

    /**
     * Take a registry back out of the index, by the root it declared.
     *
     * ## Why an index needs this at all, when a registry is described once at boot
     *
     * The index is bound `singleton()` while the front door is `scoped()`, and tenant switches happen
     * MID-REQUEST — tower's `ConduitHydrator` runs on every switch. So the standing rule is that a
     * registry is described once and what varies per tenant is its ENTRIES (ticket 41 D7), which leaves
     * this a SAFETY mechanism rather than a load-bearing one: nothing on the happy path calls it.
     *
     * It exists because the failure mode without it is silent in one direction and loud in the other. A
     * runtime-rooted registry described per tenant would either leak across tenants or throw
     * {@see Exceptions\DuplicateRegistryKey} on the next hydration, and a caller that has decided to take
     * that route needs a way back out. Whether that route is ever sanctioned is ticket 26's.
     *
     * The owner record goes with it, because {@see owner()} falling back to a store that is no longer
     * described would answer for a registry the index has forgotten.
     */
    public function forget(RegistryKey|string $key): static
    {
        $key = Key::of($key);

        if ($key->equals(Key::root())) {
            throw new InvalidArgumentException(
                'The index cannot forget itself: it describes itself at construction and declares '
                    .'Optionality::Required, so a self-hosting root that could be removed would make the '
                    .'contract aspirational in exactly the one place the package can least afford it.'
            );
        }

        $this->registries->forget($key);

        unset($this->owners[(string) $key]);

        return $this;
    }

    /**
     * Take out every registry described BY `$registrant` — the shape a tenant unwind wants.
     *
     * Paired with `describe(…, by: $selector)`: the registrant defaults to the owner's class, which makes
     * this provider-scale, but a caller that passed its own selector unwinds exactly that scale in one
     * call rather than remembering which roots it described.
     *
     * Unscoped, like {@see forget()} — provenance selects, it does not authorize.
     *
     * The owner records are pruned by DIFFERENCE rather than by asking each root who registered it,
     * because the registrant is a private field of the inner store and reading it back would mean growing
     * {@see BasicRegistry}'s public surface for one caller's bookkeeping.
     *
     * **The self-hosting entry is never a candidate.** The index describes itself under its own class
     * name, so a bulk unwind naming that class would otherwise un-host it — see {@see forget()}, which
     * refuses the same removal outright. A bulk selector is not the place for that refusal, so the entry
     * is restored instead of the whole call failing.
     */
    public function forgetBy(string $registrant): static
    {
        $before = array_map('strval', $this->registries->unfiltered()->keys());

        $this->registries->forgetBy($registrant);

        $after = array_map('strval', $this->registries->unfiltered()->keys());

        foreach (array_diff($before, $after) as $gone) {
            unset($this->owners[$gone]);
        }

        if ($this->registries->tryResolve(Key::root()) === null) {
            $this->describe($this, $this);
        }

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->registries->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->registries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->registries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->registries->matches($key);
    }

    public function keys(): array
    {
        return $this->registries->keys();
    }

    public function children(RegistryKey|string $key): array
    {
        return $this->registries->children($key);
    }

    public function descendants(RegistryKey|string $key): array
    {
        return $this->registries->descendants($key);
    }

    public function nodeAt(RegistryKey|string $key): RegistryNode
    {
        return $this->registries->nodeAt($key);
    }

    /**
     * {@see nodeAt()} over the whole KEYSPACE rather than over the index's own entries.
     *
     * ## Why the index carries two altitudes, and how to tell them apart
     *
     * The index's entries are registries, so its inherited {@see Nested} verbs — `children()`,
     * `descendants()`, `nodeAt()` — answer about ROOTS. They are the right answer to "what registries
     * live under here" and the wrong one to "what does the keyspace look like here", and the two
     * diverge the moment roots interleave (ticket 26 D0: `beam.particle` and
     * `beam.particle.fragments.ops` may both be described, and `routeTo()` already routes between them
     * by longest prefix). The `*Across` pair is the keyspace altitude. Bare verb = this index's own
     * entries; `Across` = every entry in every registry it holds.
     *
     * ## What it reports, and the one case that reads Absent
     *
     * `Entry` when the registry that OWNS the address holds one there; `Branch` when either half of
     * the union continues below it — the owning registry's own entries, or a deeper root. A registry
     * described at exactly this address but holding nothing reads **`Absent`**, deliberately: this
     * verb reports the entry keyspace, and an empty registry contributes no addresses to it. Ask
     * {@see nodeAt()} or {@see owner()} for registry membership, which is a different question with a
     * different right answer.
     */
    public function nodeAcross(RegistryKey|string $key): RegistryNode
    {
        $key = Key::of($key);
        $owner = $this->owningTree($key);

        $local = $owner === null ? RegistryNode::Absent : $owner->nodeAt($key);

        if ($local === RegistryNode::Entry) {
            return RegistryNode::Entry;
        }

        return $local === RegistryNode::Branch || $this->nodeAt($key) === RegistryNode::Branch
            ? RegistryNode::Branch
            : RegistryNode::Absent;
    }

    /**
     * The children of `$key` WHEREVER THEY LIVE — entry-children unioned with child roots, across the
     * registry boundary (ticket 46, charter expanded by ticket 26 D8).
     *
     * Nothing unioned them before this. `$index->children()` returns child roots and
     * `$registry->children()` returns child entry keys, so with `beam.particle` and
     * `beam.particle.fragments.ops` both described, no verb answered *"the children of
     * `beam.particle.fragments`"* with the ops branch that genuinely lives below it — the interleaved
     * tree ticket 26 had just sanctioned could not be enumerated or displayed.
     *
     * ## It returns bare keys, not (key, owning registry) pairs
     *
     * The pair shape was considered for the round of routing it saves a lazy tree, and refused: the
     * union's members include branches that belong to NO registry. `beam.particle.fragments` above is
     * derived from a root two segments deeper, and `routeTo()` hands it to `beam.particle`'s registry,
     * which does not hold it. A pair would therefore have to carry a null — or a lie — for exactly the
     * nodes this ticket exists to make probeable, which is a second way to ask {@see nodeAcross()}
     * rather than a saving. Bare keys also keep {@see Nested}'s signature, so a walker can switch
     * altitudes without reshaping its loop.
     *
     * ## Index-only, and `descendants()` gets no counterpart
     *
     * A flat registry has no cross-boundary question to answer, and widening `Nested` with this would
     * oblige every implementation to have an opinion about an index it may not have. The deep walk is
     * refused on ticket 17 D2's measurement: the full walk of 7,980 entries costs 9.4 ms but **423.8
     * KB of JSON for the bare keys alone**, so the decided model is registry-level eager, entry-level
     * lazy — and a cross-registry `descendants()` is the one verb that makes materializing all of it
     * an accident. A caller that genuinely wants the whole tree recurses this and pays visibly.
     *
     * ## It dedupes, though the invariant says it need not
     *
     * Ticket 26 D6 landed `assertUnshadowed()` at describe time — two described registries may not
     * both answer for one absolute key — which is what lets this be a plain merge rather than a
     * precedence rule. The dedupe is defensive anyway, because that check leaves a **residual window**
     * (a registry described before its registrars fill it, carried by ticket 49), and a duplicated
     * node in a tree walk is a silent double-render rather than a caught error. Where both halves
     * offer the same address, the owning registry's own key object wins, so a foreign key type keeps
     * its rendering.
     *
     * @return list<RegistryKey>
     */
    public function childrenAcross(RegistryKey|string $key): array
    {
        $key = Key::of($key);
        $owner = $this->owningTree($key);

        $children = $owner === null ? [] : $owner->children($key);

        foreach ($this->children($key) as $root) {
            $children[] = $root;
        }

        $found = [];

        foreach ($children as $child) {
            // Joined on NUL for the same reason BasicRegistry's identity is: segments are opaque, and
            // a foreign key may legitimately contain `.` or `/`, so joining on either could collide
            // two distinct keyspaces onto one bucket.
            $found[implode("\x00", $child->segments())] ??= $child;
        }

        return array_values($found);
    }

    /**
     * The registry whose own tree answers for `$key`, or null where the index itself would.
     *
     * Excluding `$this` is what keeps the two altitudes apart: the index is a legal routing target (it
     * declares the zero-segment root and self-hosts there), so without this a keyspace question about
     * an unrouted key would fall back to the ROOT-level answer and read as though entries lived there.
     * The {@see Nested} check is the honest one rather than an assumption — {@see Registry} does not
     * promise a tree, and a consumer's own implementation may not offer one.
     */
    private function owningTree(RegistryKey $key): ?Nested
    {
        $owner = $this->routeTo($key);

        return $owner instanceof Nested && $owner !== $this ? $owner : null;
    }

    public function unfiltered(): Registry
    {
        $unfiltered = clone $this;
        $unfiltered->authorizer = null;
        $unfiltered->registries = $this->registries->unfiltered();

        return $unfiltered;
    }

    /**
     * Where a store's declaration comes from.
     *
     * {@see BasicRegistry} carries its OWNER's declaration — `BasicRegistry::for($this)` read it off the
     * owning class — so a composed registry is asked for what it is already holding. Anything else
     * declares the attribute on itself. The `instanceof` is on the kernel's own reference implementation
     * rather than on a consumer's type, which is why it does not need an interface to hide behind.
     */
    /**
     * @template TStored
     *
     * @param  Registry<TStored>  $store
     */
    private function declarationOf(Registry $store, ?object $owner): IsRegistry
    {
        $declaration = $store instanceof BasicRegistry
            ? $store->declaration()
            : IsRegistry::of($store);

        if ($declaration === null && $owner !== null) {
            $declaration = IsRegistry::of($owner);
        }

        return $declaration ?? throw new InvalidArgumentException(sprintf(
            '`%s` has no #[IsRegistry] declaration, so the index cannot say what root it owns. A '
                .'registry describes itself; declare the attribute on the class that owns the keyspace.',
            $owner === null ? $store::class : $owner::class,
        ));
    }

    private function push(mixed $store): void
    {
        if ($store instanceof Gated && $store !== $this) {
            $store->authorizeWith($this->authorizer);
        }
    }
}
