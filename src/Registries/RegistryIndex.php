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
class RegistryIndex implements Gated, Nested, Registry
{
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
     * @throws InvalidArgumentException the store can say what root it owns
     * @throws Exceptions\DuplicateRegistryKey another registry already claims that root
     */
    public function describe(Registry $store, ?object $owner = null): static
    {
        $declaration = $this->declarationOf($store, $owner);
        $root = $declaration->rootKey();

        $this->registries->register(
            $root,
            $store,
            by: $owner === null ? $store::class : $owner::class,
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
