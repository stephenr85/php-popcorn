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
 * ## Entries are LIVE registries — and since ticket 73 there is a second, BAKED door
 *
 * `describe()` takes an object that already exists. The owner built it in its own `boot()` — the
 * register-down rule again — so `keys()` walks a list of things already in memory and instantiates
 * nothing.
 *
 * ⚠️ **This docblock used to continue: *"Holding class-strings and resolving them lazily would invert
 * the direction: the index would become the thing that decides when a consumer's registry comes into
 * being."* Registry-kernel ticket 73 D2 overruled that, and the reason is worth reading before
 * re-arguing it.** {@see describeLazily()} holds exactly that class-string, and the direction is
 * unharmed: the owner still declares, the bake still reads the owner's own `#[IsRegistry]`, and the
 * index still never scans or discovers at run time. What changed is only WHEN the object is built.
 *
 * The old rule was protecting against the index deciding a registry's construction moment. Measured,
 * eager describing is what actually causes that harm: phase A found four registries whose classes live
 * in one package and whose container bindings live in the HOST's providers, three behind configuring
 * closures — so an eager `describe($app->make(...))` from the owning package **fabricates a fresh
 * unconfigured singleton wherever nothing binds one**, failing as an ANSWER rather than an error. Lazy
 * resolution goes through the host's container at READ time, so the host's own binding is what answers.
 * Laziness is what preserves the direction rule here, not what breaks it.
 *
 * ## Three membership states, and "absent" is not "empty"
 *
 * A baked index that lists nothing is a host which genuinely declares no registries: legal, quiet. An
 * index whose artifact is MISSING knows nothing, and every membership read raises
 * {@see Exceptions\UnbakedRegistryIndex} rather than answering "nothing" — see that class for why a
 * throw is right here and wrong for shadowing one section down. The kernel's default is neither: it is
 * *"membership was supplied by hand"*, which is what every test and every non-Laravel consumer uses, and
 * only a framework adapter that went looking for an artifact can call {@see markUnbaked()}.
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
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Required,
    note: 'Roots must be unique, and a duplicate is RECORDED rather than fatal: the later describe() '
        .'takes the root in the earlier one\'s slot and the displaced registry is readable through '
        .'superseded(), carrying its registrant and sequence. That is strictly more information than a '
        .'throw, which names two colliding packages and then dies before anything can enumerate the '
        .'rest — and a root collision depends on which host loaded which providers, which is the one '
        .'thing the estate has ruled must not be fatal at boot (registry-kernel 34, landed by 48).',
    order: 0,
)]
class RegistryIndex implements Forgettable, Gated, Nested, RecordsRegistrants, RecordsSupersession, Registry
{
    /** @var BasicRegistry<Registry<mixed>> */
    private BasicRegistry $registries;

    /** @var array<string, object> owner objects, keyed by their registry's rendered root */
    private array $owners = [];

    private ?Authorizer $authorizer = null;

    /**
     * Entries that went dark because two described roots overlap on them — ticket 73 §1's record, in
     * place of the throw `describe()` used to raise. See {@see recordShadowing()} and {@see shadowed()}.
     *
     * A flat list rather than a map keyed by root, because a shadowing is a fact about a PAIR of roots
     * and keying it by either one would make the other a value that reads like a detail. {@see shadowed()}
     * does the filtering, on whichever end the caller is standing.
     *
     * @var list<Shadowed>
     */
    private array $shadowed = [];

    private int $shadowSequence = 0;

    /**
     * Roots BAKED but not yet resolved: rendered root => the class-string that provides the registry.
     *
     * Registry-kernel ticket 73 D2's half that does more than save time. A baked entry costs a string
     * until something actually routes to it, so boot autoloads nothing and constructs nothing — which is
     * what discharges the forced-construction hazard rather than mitigating it, and what makes the host's
     * OWN container binding the thing that answers, because {@see hydrate()} resolves through the
     * caller-supplied resolver at READ time rather than at bake time.
     *
     * @var array<string, class-string>
     */
    private array $pending = [];

    /**
     * The registrant to record for a pending root when it is finally resolved — the bake knows who
     * declared it, and that provenance must survive until hydration or `keysBy()` loses it.
     *
     * @var array<string, string|null>
     */
    private array $pendingBy = [];

    /**
     * How a pending class-string becomes the live registry — supplied by the framework adapter, because
     * the kernel has no container and must not grow one.
     *
     * @var (\Closure(class-string): object)|null
     */
    private ?\Closure $resolver = null;

    /**
     * Non-null when the membership list was EXPECTED and is absent; the operator-facing reason.
     *
     * ⚠️ The kernel's default is null — *"membership was supplied by hand"* — and it stays that way for
     * every package testbench, every existing test and every non-Laravel consumer, all of which build an
     * index and {@see describe()} into it. Only an adapter that went looking for an artifact can know one
     * was missing, so only an adapter calls {@see markUnbaked()}. See {@see Exceptions\UnbakedRegistryIndex}.
     */
    private ?string $unbaked = null;

    /**
     * Baked roots whose class could not be resolved in THIS composition, keyed by root, with the
     * resolver's message. See {@see hydrate()} for why this is a record and not a throw.
     *
     * @var array<string, string>
     */
    private array $unresolvable = [];

    /**
     * Whether this index is a deep-unfiltered view: the registries it hands back are unfiltered too.
     * Set only by {@see unfiltered()}, never by a host — see that method for why it is not a one-level
     * escape (registry-kernel ticket 45).
     */
    private bool $deep = false;

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
     */
    public function describe(Registry $store, ?object $owner = null, ?string $by = null): static
    {
        $declaration = $this->declarationOf($store, $owner);
        $root = $declaration->rootKey();
        $by ??= $owner === null ? $store::class : $owner::class;

        // A hand describe SUPERSEDES a pending bake for the same root, and CLEARS blindness. Both halves
        // are what make the cutover survivable in either order.
        //
        // "Unbaked" means *no membership source at all*. An index that is being described into has one —
        // whatever the artifact's state — so a host still running hand-written `describe()` calls behaves
        // exactly as it always did, and only a host with neither an artifact nor a describe is blind.
        // That is the state the collapse creates and the state D3.2 exists to make loud.
        //
        // The self-hosting describe from the constructor is deliberately NOT a membership source: the
        // index describing itself says nothing about whether anything else is described, and counting it
        // would make every index look supplied and the blindness unreachable.
        if ($store !== $this) {
            $this->unbaked = null;

            unset($this->pending[(string) $root], $this->pendingBy[(string) $root]);
        }

        $this->recordShadowing($store, $root, $by);

        $this->registries->register(
            $root,
            $store,
            by: $by,
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
     * Take a registry into the index LAZILY: its declared root now, its instance on first read.
     *
     * The baked half of registry-kernel ticket 73. `$root` comes off the class's `#[IsRegistry]` at BAKE
     * time and `$class` is what provides the registry; nothing is autoloaded or constructed here, so a
     * host with 85 declared registries pays 85 array writes at boot instead of 85 constructions.
     *
     * **Resolution goes through {@see resolveLazilyWith()}'s resolver at READ time**, and that is not an
     * implementation detail — it is the reason the collapse is safe. Phase A measured four tower
     * registries whose classes live in one package and whose container bindings live in the HOST's
     * providers, three of them behind configuring closures. An eager `describe($app->make(...))` from the
     * owning package fabricates a fresh unconfigured singleton wherever nothing binds one; resolving at
     * read time means the host's own binding is what answers, whatever it is.
     *
     * @param  class-string  $class
     */
    public function describeLazily(string $root, string $class, ?string $by = null): static
    {
        $this->pending[$root] = $class;
        $this->pendingBy[$root] = $by;

        return $this;
    }

    /**
     * How a pending class-string becomes an object — the container's `make()`, in a Laravel host.
     *
     * Injected rather than assumed because the kernel is framework-agnostic and has no container. With no
     * resolver a pending root falls back to `new $class`, which is right for a plain PHP consumer and
     * wrong for a host, so an adapter always sets one.
     *
     * @param  (callable(class-string): object)|null  $resolver
     */
    public function resolveLazilyWith(?callable $resolver): static
    {
        $this->resolver = $resolver === null ? null : \Closure::fromCallable($resolver);

        return $this;
    }

    /**
     * Declare that a membership list was expected here and is ABSENT, so every read raises rather than
     * reporting an empty estate (ticket 73 D3.2).
     *
     * @see Exceptions\UnbakedRegistryIndex for why this is a throw, and why it is raised at the door
     *      rather than at boot
     */
    public function markUnbaked(string $reason): static
    {
        $this->unbaked = $reason;

        return $this;
    }

    /**
     * Baked roots this composition could not build, keyed by root — normally empty.
     *
     * @return array<string, string>
     */
    public function unresolvable(): array
    {
        return $this->unresolvable;
    }

    /** Whether a membership list was expected here and is absent. */
    public function isUnbaked(): bool
    {
        return $this->unbaked !== null;
    }

    /**
     * Roots that are baked and not yet resolved — rendered root => providing class-string.
     *
     * For tooling that wants to show what the index KNOWS without forcing it to construct anything.
     *
     * @return array<string, class-string>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    /**
     * Raise if a membership list was expected here and is absent.
     *
     * Called at the top of every read that consults membership, and NOT from {@see describe()} or the
     * constructor: an unbaked index must still be constructible and still be describable, or the command
     * that writes the artifact — which boots the application — could never run.
     *
     * @throws Exceptions\UnbakedRegistryIndex
     */
    private function guard(): void
    {
        if ($this->unbaked !== null) {
            throw new Exceptions\UnbakedRegistryIndex($this->unbaked);
        }
    }

    /**
     * Resolve ONE pending root into a live registry, if that root is still pending.
     *
     * This is the whole lazy path: the class is autoloaded here, constructed by the caller-supplied
     * resolver here, shadow-checked here, and pushed the {@see Gated} authorizer here. **The authorizer
     * push happens at resolution and not at bake**, which is the half that is not about visibility — a
     * registry that appeared in the index without being pushed would be an unauthorized one, and lazy
     * resolution is exactly where that could be dropped silently.
     */
    private function hydrate(string $root): void
    {
        if (! array_key_exists($root, $this->pending)) {
            return;
        }

        $class = $this->pending[$root];
        $by = $this->pendingBy[$root] ?? $class;

        // Unset BEFORE resolving: a constructor that itself routes a key would otherwise re-enter here
        // and recurse forever. The estate has constructor-seeded registries, so this is not theoretical.
        unset($this->pending[$root], $this->pendingBy[$root]);

        try {
            $instance = $this->resolver === null ? new $class : ($this->resolver)($class);
        } catch (\Throwable $e) {
            // ⚠️ A baked root whose class cannot be RESOLVED here is a composition fact, not a defect,
            // and this estate's standing rule is that a composition fact must not be fatal.
            //
            // The bake reads DECLARATIONS off the filesystem, and a declaration's container binding
            // frequently lives in a different package's provider — measured across this ticket as the
            // normal case rather than the exception. So an environment that composes the declaring
            // package without the binding one finds a class it cannot build. Measured 2026-08-31:
            // `Splicewire\Beam\Doctor\Support\FacadeConformanceScope` declares
            // `beam.doctor.facade-scope` and takes a required `array $roots`, so every package
            // testbench that vendors beam's source but does not register `BeamServiceProvider` hit
            // `Unresolvable dependency` — six suites, none of them wrong.
            //
            // RECORDED rather than swallowed, on the same reasoning as `Shadowed` and the scanner's
            // `unloadable()`: a root that quietly disappears is exactly what this ticket exists to
            // remove. It stays out of the index, `routeTo()` reports an honest miss, and
            // `UnindexedRegistryAudit` sees it as unindexed — which at a host that SHOULD compose it is
            // a real finding, and at one that should not is the correct answer.
            $this->unresolvable[$root] = $e->getMessage();

            return;
        }

        if ($instance instanceof Registry) {
            $this->describe($instance, by: $by);

            return;
        }

        // ⚠️ The baked path takes CONFORMING registries only, and that is a measured constraint rather
        // than a simplification. The sanctioned composition pattern (ticket 01 D1) lets an owner HOLD a
        // `BasicRegistry` instead of implementing `Registry`, and for that shape the two-argument
        // `describe($store, $owner)` needs the inner store — which {@see CarriesDeclaration} does not
        // expose and deliberately never has (it answers `declaration()`, not `store()`).
        //
        // It costs nothing today: measured 2026-08-31 at `~/Herd/splicewire-app`, 84 of the 85 declared
        // classes on disk implement the contract, and the one that does not
        // (`Schemastud\DataSchemas\Overlay\InMemoryOverlayRegistry`) cannot be described by ANY route —
        // `describe()` has always required a `Registry`. Every owner-form registry in the estate, this
        // index included, implements the contract itself and is described one-argument today.
        //
        // So a class reaching here is a conformance failure, and it is already owned and GATED by
        // `Splicewire\Beam\Doctor\RegistryConformanceAudit`'s `contract` check. Naming that audit is the
        // whole message: the fix is upstream of the bake, not in it.
        throw new InvalidArgumentException(sprintf(
            '`%s` is baked at root `%s` but does not implement `%s`, so the index cannot take it. A '
                .'declared registry must implement the contract — this is the `contract` check of the '
                .'registry-conformance audit, failing at run time because the bake believed the '
                .'declaration.',
            $class,
            $root,
            Registry::class,
        ));
    }

    /**
     * Resolve EVERY pending root.
     *
     * Enumeration cannot be lazy — `keys()`, a tree walk and `popcorn:registries` all need the whole set
     * — so this is where the cost lands. That is deliberate and it is the right trade: **boot never
     * enumerates**, so the hot path stays lazy and the price is paid by tooling that was always going to
     * touch everything anyway.
     */
    private function hydrateAll(): void
    {
        while ($this->pending !== []) {
            $this->hydrate(array_key_first($this->pending));
        }
    }

    /**
     * RECORD a root that makes an already-registered entry unreadable through the index (ticket 26 D5 as
     * amended by ticket 73).
     *
     * Interleaved roots are LEGAL — {@see routeTo()} handles nesting by construction. What is recorded is
     * the narrower case where one absolute key falls inside two described registries, because then the
     * answer depends on which door the caller entered. Both directions are checked, since a registry may
     * be described before or after the one it nests with:
     *
     *  - the incoming root sits under an existing registry that already holds a key at or below it;
     *  - an existing root sits under the incoming registry, which already holds a key at or below THAT.
     *
     * ## It used to throw, and ticket 73 §1 traded the fatality for the record
     *
     * `Exceptions\ShadowedRegistryKey` is **deleted**, not deprecated. Whether two described registries
     * overlap is a fact about **which providers this host loaded** — the same package pair collides at
     * one install and not at another — and the estate's standing rule is that a check whose answer
     * depends on the host is an advisory finding, never a boot failure. A throw here made a host
     * composition decision fatal in a kernel that cannot know the composition, which is the shape that
     * has already stopped a Herd root booting once on a different check.
     *
     * This is the same trade ticket 34 made for duplicate ROOTS and ticket 48 landed, in the same shape:
     * a {@see Shadowed} record carrying both roots, the registrant and a sequence, read back through
     * {@see shadowed()}. It was also the prerequisite for ticket 73's automatic describe pass — an
     * automatic pass over every declared registry turns any overlap in the estate into a boot failure at
     * every host at once.
     *
     * **Detection does not merely survive the trade, it improves.** The describe-time walk can only see
     * entries that already exist at describe time, and a registry is usually described before its
     * registrars fill it. `Splicewire\Beam\Doctor\RegistryConformanceAudit::shadowedEntries()` reads the
     * LIVE index after boot and sees a strict superset of this, and it GATES. So the throw was never the
     * instrument that caught the estate's shadowing; it was the one that caught the fraction visible
     * earliest, at the cost of being fatal.
     *
     * **The self-hosting entry is never a party to this.** The index's root is zero-segment, i.e. a
     * prefix of every key in the estate, and its "entries" are roots rather than entry keys — checking it
     * would report every registry there is, on a category error.
     */
    /**
     * @template TStored
     *
     * @param  Registry<TStored>  $incoming
     */
    private function recordShadowing(Registry $incoming, RegistryKey $root, ?string $by): void
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
                $this->recordShadowed($existing, $root, $existingRoot, $root, $by);
            }

            if ($this->isUnder($existingRoot, $root->segments())) {
                $this->recordShadowed($incoming, $existingRoot, $root, $existingRoot, $by);
            }
        }
    }

    /**
     * Look for keys in `$holder` at or below `$boundary`, and record each one that goes dark.
     *
     * `$shallower` and `$deeper` are the two roots as the record needs them, rather than being re-derived
     * here: the caller already knows which of the pair is the outer one and passing it beats guessing.
     *
     * EVERY overlapping key is recorded, not just the first. The throwing version stopped at one because
     * it was dying anyway; a report that names one of five entries and stops is the estate's own
     * complaint about a throw — *"it names two colliding packages and then dies before anything can
     * enumerate the rest"* — reproduced inside the replacement.
     */
    /**
     * @template TStored
     *
     * @param  Registry<TStored>  $holder
     */
    private function recordShadowed(
        Registry $holder,
        RegistryKey $boundary,
        RegistryKey $shallower,
        RegistryKey $deeper,
        ?string $by,
    ): void {
        foreach ($holder->unfiltered()->keys() as $key) {
            if ($key->equals($boundary) || $this->isUnder($key, $boundary->segments())) {
                $this->shadowed[] = new Shadowed($key, $shallower, $deeper, $by, $this->shadowSequence++);
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
        $this->guard();

        $key = Key::of($key);

        // The longest-prefix walk runs over roots the index KNOWS — resolved and pending alike — because
        // a baked root is every bit as described as a resolved one; only its instance is deferred. This
        // is the single hot path that must stay lazy, so it compares strings and hydrates exactly the one
        // root that wins, never the set.
        $best = null;
        $depth = -1;

        foreach ($this->knownRoots() as $root) {
            $prefix = $root->segments();

            if (! $key->equals($root) && ($prefix === [] || ! $this->isUnder($key, $prefix))) {
                continue;
            }

            if (count($prefix) > $depth) {
                $best = $root;
                $depth = count($prefix);
            }
        }

        if ($best === null) {
            return null;
        }

        $this->hydrate((string) $best);

        // `tryResolve`, not `resolve`: hydration can decline. A baked root whose class this composition
        // cannot build is recorded by {@see hydrate()} and never registered, so the honest answer here
        // is the same null a genuinely unclaimed key gets — the reason is in {@see unresolvable()}.
        $store = $this->registries->unfiltered()->tryResolve($best);

        return $store === null ? null : $this->reveal($store);
    }

    /**
     * Every root the index knows about — resolved plus still-pending — as {@see RegistryKey}s.
     *
     * A pending root is stored as the rendered string the bake wrote, so it is re-keyed through
     * {@see Key::of()} here. That is safe for every root the bake can produce (they come off
     * `#[IsRegistry(root:)]`, which is a dotted `Key` by declaration) and it is why a FOREIGN key type
     * cannot be baked — such a registry keeps the hand-written `describe()` it always had.
     *
     * @return list<RegistryKey>
     */
    private function knownRoots(): array
    {
        $roots = $this->registries->unfiltered()->keys();

        foreach (array_keys($this->pending) as $root) {
            // `Key::of('')` is not legal — the zero-segment root is `Key::root()`, and it belongs to the
            // index alone. A bake must never emit it (that is the bake's rule, and it has its own
            // comment), but routing must not die if one ever arrives.
            $roots[] = $root === '' ? Key::root() : Key::of($root);
        }

        return $roots;
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
        $this->guard();

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
        $this->guard();
        $this->hydrateAll();

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
        $this->guard();
        $this->hydrateAll();

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

        $this->pruneShadowRecords();

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

        $this->pruneShadowRecords();

        return $this;
    }

    /**
     * Drop shadow records whose overlap no longer exists, because one of its two roots is gone.
     *
     * Driven by what is DESCRIBED rather than by the registrant that was forgotten, so it cannot drift
     * from {@see forget()} and {@see forgetBy()} taking different routes to the same removal. Keeping a
     * record past its condition would be the leak {@see Forgettable::forget()}'s own docblock argues
     * against for {@see Superseded}, and worse here: a stale record reports an overlap a reader can no
     * longer see in the index, which is indistinguishable from the reader being broken.
     */
    private function pruneShadowRecords(): void
    {
        $live = array_map('strval', $this->registries->unfiltered()->keys());

        $this->shadowed = array_values(array_filter(
            $this->shadowed,
            fn (Shadowed $s) => in_array((string) $s->shallower, $live, true)
                && in_array((string) $s->deeper, $live, true),
        ));
    }

    public function has(RegistryKey|string $key): bool
    {
        $this->guard();
        $this->hydrateAll();

        return $this->registries->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        $this->guard();
        $this->hydrateAll();

        return $this->reveal($this->registries->resolve($key));
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        $this->guard();
        $this->hydrateAll();

        return $this->reveal($this->registries->tryResolve($key));
    }

    public function matches(RegistryKey|string $key): array
    {
        $this->guard();
        $this->hydrateAll();

        return array_map(fn (mixed $store): mixed => $this->reveal($store), $this->registries->matches($key));
    }

    public function keys(): array
    {
        $this->guard();
        $this->hydrateAll();

        return $this->registries->keys();
    }

    /**
     * {@see RecordsRegistrants} — which package or provider described the registry at this root.
     *
     * The index's own `by` is written by {@see describe()} on every call: the explicit `by:` argument
     * where one is passed, otherwise the owner's class, otherwise the store's. So unlike the entry-level
     * population ticket 29 D2 measured, this one is **never null** — the index is the one place the
     * registrant vocabulary is already total (registry-kernel ticket 48).
     */
    public function registrantOf(RegistryKey|string $key): ?string
    {
        $this->guard();
        $this->hydrateAll();

        return $this->registries->registrantOf($key);
    }

    /** {@see RecordsRegistrants} — every root described by `$registrant`. */
    public function keysBy(string $registrant): array
    {
        $this->guard();
        $this->hydrateAll();

        return $this->registries->keysBy($registrant);
    }

    /**
     * {@see RecordsSupersession} — what was displaced at this root.
     *
     * Empty until something is displaced, and under registry-kernel ticket 48 the index CAN now displace:
     * see the `onDuplicate` note on the class attribute for why a duplicate root became a recorded
     * supersession rather than a boot-time throw.
     */
    public function superseded(RegistryKey|string $key): array
    {
        return $this->registries->superseded($key);
    }

    /**
     * Entries that went dark: each key a described registry holds at an address a NESTED described
     * registry owns (ticket 73 §1).
     *
     * Empty on a healthy estate, and empty is the normal reading — this is the record that replaced
     * `describe()`'s throw, not a routine event. `$root` filters to the records naming that root at
     * EITHER end, because "what did my registry shadow" and "what shadowed my registry" are the same
     * question asked from the two doors, and a caller holding one root should not have to know which end
     * it is on.
     *
     * ⚠️ **An empty list is not a clean estate.** This can only see overlaps that existed at the moment
     * one of the two registries was described, and a registry is usually described before its registrars
     * fill it — so the entry that collides most often does not exist yet. The instrument that sees the
     * rest is `Splicewire\Beam\Doctor\RegistryConformanceAudit::shadowedEntries()`, which reads the live
     * index after boot and gates. Read this for the provenance it carries (who described what, and in
     * what order) that a post-boot scan of live state cannot reconstruct; read the audit for coverage.
     *
     * @return list<Shadowed> oldest first
     */
    public function shadowed(RegistryKey|string|null $root = null): array
    {
        if ($root === null) {
            return $this->shadowed;
        }

        $root = Key::of($root);

        return array_values(array_filter(
            $this->shadowed,
            fn (Shadowed $s) => $s->shallower->equals($root) || $s->deeper->equals($root),
        ));
    }

    public function children(RegistryKey|string $key): array
    {
        $this->guard();
        $this->hydrateAll();

        return $this->registries->children($key);
    }

    public function descendants(RegistryKey|string $key): array
    {
        $this->guard();
        $this->hydrateAll();

        return $this->registries->descendants($key);
    }

    public function nodeAt(RegistryKey|string $key): RegistryNode
    {
        $this->guard();
        $this->hydrateAll();

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
     * Ticket 26 D6 landed a describe-time shadow check — two described registries should not both
     * answer for one absolute key — which is what lets this be a plain merge rather than a precedence
     * rule. The dedupe is defensive anyway, and more so since ticket 73 §1 made that check RECORD
     * rather than throw ({@see shadowed()}): an overlap is now reported and permitted, on top of the
     * **residual window** it always left (a registry described before its registrars fill it, carried
     * by ticket 49). A duplicated node in a tree walk is a silent double-render rather than a caught
     * error, so the merge does not rely on the check at all. Where both halves
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

    /**
     * The index with no authorizer, **all the way down** — the registries it hands back are unfiltered
     * too (registry-kernel ticket 45).
     *
     * ## It escaped one level and reported success
     *
     * Until ticket 45 this unfiltered *which registries you can see* and handed back the live, still-gated
     * singletons, so their ENTRIES stayed filtered. Measured three times, in three unrelated probes, and
     * each one read as agreement rather than as a defect:
     *
     * ```
     * index->unfiltered()->tryResolve($root)->keys()                 →  5 of 10
     * index->unfiltered()->tryResolve($root)->unfiltered()->keys()   → 10 of 10
     * ```
     *
     * Ticket 17's enumeration probe reported 4,030 of 7,980 for both the filtered and the "unfiltered"
     * walk; ticket 29's `GraphSource` had to call `->unfiltered()` on every registry by hand to reach
     * its 63-node count. **Zero measured callers wanted the one-level reading and three worked around
     * it**, which is what makes this a defect rather than a taste — the same class as ticket 04 D1's
     * `class_exists` guard, ticket 14's `require-dev` gate, 35 D3's symlink blindness and 41 D11's
     * attribute non-inheritance: *visibility silently becoming a function of where you stand.*
     *
     * ## Why the index and only the index
     *
     * Only the index composes other registries, so only the index can be deep. A {@see Nested}
     * registry's branches are its OWN store's records and are already filtered or unfiltered with it —
     * `children()`, `descendants()` and `nodeAt()` read the same entry list `keys()` does, so there is
     * no second instance of this defect one level down. {@see nodeAcross()} and {@see childrenAcross()}
     * cross into an owned tree through {@see routeTo()} and therefore inherit the depth for free.
     *
     * ## What comes back is the STORE, not the port
     *
     * A composed registry's `unfiltered()` returns its inner {@see BasicRegistry}, not itself — that is
     * the uniform estate implementation (`return $this->entries->unfiltered();`), and it is what every
     * hand-rolled workaround was already getting. So the kernel view is what a deep read yields, and
     * the port's domain sugar is not. Reach for {@see owner()} when you want the port; it is
     * deliberately NOT deepened, because an owner need not be a registry at all.
     *
     * ## Still artisan-only
     *
     * Ticket 09 D11's trusted-shell policy is unchanged and deepening does not widen it. Whether a
     * *browser* may ever be handed a tree baked through this door is a disclosure decision, not a depth
     * one, and ticket 45 leaves it open — see its residue on the map.
     */
    public function unfiltered(): Registry
    {
        $this->guard();
        $this->hydrateAll();

        $unfiltered = clone $this;
        $unfiltered->authorizer = null;
        $unfiltered->deep = true;
        $unfiltered->registries = $this->registries->unfiltered();

        return $unfiltered;
    }

    /**
     * Hand back a described store the way this index reads: as it is, or unfiltered under a deep read.
     *
     * `mixed` in and out rather than `Registry` in both slots, because this sits on the paths that
     * return whatever the index holds — the entry type is `Registry<mixed>` by declaration, but the
     * kernel does not enforce that at the call site and a hostile write would otherwise trip a cast
     * here rather than at the door where it belongs.
     */
    private function reveal(mixed $store): mixed
    {
        return $this->deep && $store instanceof Registry ? $store->unfiltered() : $store;
    }

    /**
     * Where a store's declaration comes from.
     *
     * **A store that carries its declaration as a VALUE is asked; everything else declares on its
     * class.** {@see BasicRegistry} is the first case — `BasicRegistry::for($this)` read the attribute
     * off the owning class and has held it ever since, so a composed registry is asked for what it is
     * already holding.
     *
     * This used to `instanceof BasicRegistry`, defended on the grounds that the test was on the
     * kernel's own reference implementation rather than on a consumer's type. **Registry-kernel ticket
     * 59 B1 measured the population that defence did not anticipate**: an archetype-**f** registry over
     * an external store holds no `BasicRegistry` — holding no array is its defining property — so it
     * fell through to the class attribute, which is exactly what ticket 26 D2 forbids for that family
     * (one class, four roles, one root asserted across all of them). The type test is now
     * {@see CarriesDeclaration}, which `BasicRegistry` implements, so the kernel's own case is
     * unchanged and f can finally declare inline as the sweep brief's §3a-f step 5 prescribes.
     */
    /**
     * @template TStored
     *
     * @param  Registry<TStored>  $store
     */
    private function declarationOf(Registry $store, ?object $owner): IsRegistry
    {
        $declaration = $store instanceof CarriesDeclaration
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
