<?php

namespace Rushing\Popcorn;

use InvalidArgumentException;
use Rushing\Popcorn\Contracts\Invocable;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\Forgettable;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\HasRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Nested;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\RegistryNode;

/**
 * Resolves a capability name to whatever currently answers it. Registering a new invocable under an
 * existing name overrides it — the seam where a host swaps a local default for a tenant's webhook
 * without callers changing.
 *
 * ## The reduction proof, made structural
 *
 * This class is registry-kernel ticket 11's exemplar: the first estate registry cut onto {@see Registry},
 * and the one that had to prove the contract costs its adopters nothing. It does, and the migration
 * LOST code — the name-keyed array, the duplicate handling and the miss throw all move into
 * {@see BasicRegistry}, held as a field rather than extended (ticket 01 D1).
 *
 * ## What stayed, and why each is sugar rather than a second semantics
 *
 * - **`register(Invocable)`** — one-argument self-keying, over {@see HasRegistryKey} where the entry
 *   ships one and `name()` otherwise. The contract's explicit-key form is still there underneath; this
 *   is the widened door, not a parallel one (ticket 01 D2).
 * - **`invoke()`** — sugar over `resolve()->invoke()`, confirmed by 11 §1 as dispatch and not a second
 *   resolution.
 * - **`names()`** — `keys()` rendered as the strings callers registered by. It exists because 56 live
 *   call sites read names as strings, and it strips the declared root: keys go relative in and absolute
 *   out (ticket 20 D2), so `keys()` reports `popcorn.invocables.music.render` where every one of those
 *   call sites means `music.render`.
 *
 * ## An unparseable NAME is a miss, not a malformed key
 *
 * `get()` and `invoke()` take a capability name that is routinely CUSTOMER-authored — the live case is
 * `splicewire/laravel-grounding-kernel`'s `PopcornSourceResolver`, which builds `grounding.source.<type>`
 * out of a key lifted straight from a customer's schema. A typo there must surface as
 * {@see RegistryMiss}, because the app's own contract designs for a loud miss (*"a schema naming an
 * unbound pull is a misconfiguration, never a silent ungrounded generation"*) and `InvalidRegistryKey`
 * would report it as a developer error in the wrong package.
 *
 * So the two name-taking sugar methods translate an illegal name into an absent one. The CONTRACT
 * methods do not: `resolve()`, `has()` and the rest take a key, an illegal key is a programming error,
 * and softening them would put a guess back at the door.
 *
 * @implements Registry<Invocable>
 */
#[IsRegistry(
    root: 'popcorn.invocables',
    of: 'named, transport-agnostic capabilities — array in, array out',
    arity: RegistryArity::PickOne,
    entryType: Invocable::class,
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
    note: 'Duplicate names are the swap seam, not an accident: re-registering a name overrides the '
        .'prior binding, so a package default swaps for a host- or tenant-specific one without any '
        .'caller changing. Live case: `Rushing\PrismPlus\PrismPlusManager::register()`, whose own '
        .'capability registries are seeded with built-in providers a host then overrides by name.',
)]
class InvocableRegistry implements Forgettable, Gated, Nested, Registry
{
    /** @var BasicRegistry<Invocable> */
    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Register an invocable under its own name, or an explicit entry under an explicit key.
     *
     * The parameter is WIDENED from the contract rather than shadowing it — contravariance, so this is
     * still `Registry::register()` and every contract caller keeps working. An {@see Invocable} arriving
     * alone keys itself; {@see HasRegistryKey} wins over `name()` where an entry ships one, which is how
     * `schemastud/laravel-json-ns` binds by namespace URI without the kernel learning what a URI is.
     */
    public function register(RegistryKey|string|Invocable $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof Invocable) {
            $entry = $key;
            $key = $key instanceof HasRegistryKey ? $key->registryKey() : $key->name();
        }

        // The widened first parameter makes `$entry` optional, and an optional entry defaults to null —
        // which this registry DECLARES it cannot hold (`entryType: Invocable::class`). Found statically
        // by the level-8 run once `Registry` carried `@template TEntry`: `register('name')` alone typed
        // as writing `Invocable|null` into a `BasicRegistry<Invocable>`. Refused here rather than at
        // pop time, where the failure is a null-method-call several frames from the mistake.
        if (! $entry instanceof Invocable) {
            throw new InvalidArgumentException(
                'InvocableRegistry holds Invocables, so `register($key, $entry)` needs one: pass the '
                    .'Invocable as the only argument to have it key itself, or as `$entry` under an '
                    .'explicit key. Registering a name with no entry would store null under a key the '
                    .'declaration says holds an Invocable.'
            );
        }

        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    public function keys(): array
    {
        return $this->entries->keys();
    }

    /**
     * The keyspace read as a tree — {@see Nested}, forwarded to the store that already implements it.
     *
     * Capability names have ALWAYS been dotted families (`grounding.source.<type>`,
     * `screening.source.<type>`, `music.render.sampler`), and consumers enumerated them by
     * `str_starts_with()` over `names()` because there was nothing else to reach for. That is a
     * character-wise test standing in for a segment-wise one: `screening.sources` passes a
     * `screening.source` prefix check, and once every owner has its own root the check is also
     * scanning a keyspace the consumer does not own. Both walks here are segment-wise.
     */
    public function children(RegistryKey|string $key): array
    {
        return $this->entries->children($key);
    }

    public function descendants(RegistryKey|string $key): array
    {
        return $this->entries->descendants($key);
    }

    public function nodeAt(RegistryKey|string $key): RegistryNode
    {
        return $this->entries->nodeAt($key);
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    /**
     * Remove an invocable by name — the teardown half of a per-tenant overlay: a host projects
     * tenant-scoped invocables on a tenant switch and forgets them on revert, so nothing bleeds across
     * tenants on a shared worker. A no-op if absent.
     */
    public function forget(RegistryKey|string $key): static
    {
        $this->entries->forget($key);

        return $this;
    }

    public function forgetBy(string $registrant): static
    {
        $this->entries->forgetBy($registrant);

        return $this;
    }

    /**
     * The invocable answering `$name`.
     *
     * @throws RegistryMiss no invocable is registered under that name, or the name is not a legal key
     */
    public function get(string $name): Invocable
    {
        return $this->entries->resolve($this->name($name));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws RegistryMiss
     */
    public function invoke(string $name, array $input): array
    {
        return $this->get($name)->invoke($input);
    }

    /**
     * The names callers registered by — {@see keys()} with the declared root stripped back off.
     *
     * A foreign key has no root stamped onto it and is rendered by its owner, so it comes back whole.
     *
     * @return string[]
     */
    public function names(): array
    {
        return $this->entries->relativeKeys();
    }

    /**
     * A capability name as a key, with an unparseable one reported as absent rather than malformed.
     *
     * @throws RegistryMiss
     */
    private function name(string $name): RegistryKey
    {
        return Key::tryParse($name) ?? throw RegistryMiss::absent($name);
    }
}
