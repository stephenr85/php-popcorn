<?php

namespace Rushing\Popcorn\Registries;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\Exceptions\AmbiguousRegistryMatch;
use Rushing\Popcorn\Registries\Exceptions\DuplicateRegistryKey;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;

/**
 * The contract, implemented once, for a registry to HOLD AS A FIELD.
 *
 * Not a base class and not for extending — that door is closed by the estate rather than by a `final`
 * keyword (`GraphStoreManager` already extends Laravel's `Manager`, and the house style has no `final`
 * anyway). The intended shape is composition:
 *
 * ```php
 * #[IsRegistry(root: 'beam.particle.resources', of: '…', arity: RegistryArity::PickOne)]
 * class ParticleResourceRegistry
 * {
 *     private BasicRegistry $entries;
 *
 *     public function __construct()
 *     {
 *         $this->entries = BasicRegistry::for($this);   // reads this class's own declaration
 *     }
 *
 *     public function get(string $key): ParticleResource   // the existing caller-facing sugar stays
 *     {
 *         return $this->entries->resolve($key);
 *     }
 * }
 * ```
 *
 * A registry may equally implement {@see Registry} itself and delegate method-for-method; this class is
 * what stops that being 200 lines of arrays in 50 packages.
 *
 * ## One flat list, in registration order
 *
 * Entries are held as an ordered list of records rather than a `[key => entry]` map, and that is the
 * decision that makes {@see Registry::matches()}'s ordering guarantee free rather than a sort:
 * registration order IS the ordering key, so there is no `rank` field able to disagree with it. It is
 * also what lets {@see OnDuplicate::Admit} keep two live entries under one key without a nested-array
 * special case.
 *
 * ## What it does NOT do
 *
 * No merge, no normalisation, no reach. `resolve()` never walks up a key — the tree is for enumeration,
 * routing and display, and flat for resolution. See {@see Registry} and {@see Key} for why each of
 * those is an absence rather than an omission.
 */
class BasicRegistry implements Forgettable, Nested, RecordsSupersession, Registry
{
    /** @var list<array{key: string, entry: mixed, by: string|null, ability: string|null, sequence: int}> */
    private array $entries = [];

    /** @var array<string, list<Superseded>> */
    private array $superseded = [];

    private int $sequence = 0;

    public function __construct(
        private IsRegistry $declaration,
        private ?Authorizer $authorizer = null,
    ) {}

    /**
     * Build a store from the declaration on the owning class — the composition entry point.
     *
     * Throws rather than defaulting when the owner declares nothing: an undeclared registry is exactly
     * what the surgeon gate exists to catch, and silently inventing a root here would hide it from the
     * one mechanism that can see it.
     */
    public static function for(object|string $owner): static
    {
        $declaration = IsRegistry::of($owner);

        if ($declaration === null) {
            $class = is_object($owner) ? $owner::class : $owner;

            throw new InvalidArgumentException(
                "`{$class}` has no #[IsRegistry] declaration, so it cannot say what root it owns, what "
                    .'it is a registry of, or what a duplicate key means to it. Declare one, or construct '
                    .'BasicRegistry with an explicit IsRegistry.'
            );
        }

        return new static($declaration);
    }

    public function declaration(): IsRegistry
    {
        return $this->declaration;
    }

    public function root(): string
    {
        return $this->declaration->root;
    }

    /**
     * Install the host's authorizer.
     *
     * Present so the index can push one authorizer down into the registries it composes — per ticket 09
     * D7 there is exactly ONE, held by the index, because per-registry wiring means a registry that
     * forgets to wire it is silently open. The attachment point itself is ticket 20's.
     */
    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->authorizer = $authorizer;

        return $this;
    }

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $key = (string) Key::of($key);

        if ($this->declaration->onDuplicate !== OnDuplicate::Admit) {
            $occupant = $this->recordAt($key);

            if ($occupant !== null && $this->declaration->onDuplicate === OnDuplicate::Reject) {
                throw DuplicateRegistryKey::for($key, $by, $occupant['by']);
            }

            if ($occupant !== null) {
                $this->supersede($occupant);
            }
        }

        $this->entries[] = [
            'key' => $key,
            'entry' => $entry,
            'by' => $by,
            'ability' => $ability,
            'sequence' => $this->sequence++,
        ];

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->visibleAt((string) Key::of($key)) !== [];
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        $key = (string) Key::of($key);

        if ($this->entries === [] && $this->declaration->optionality === Optionality::Required) {
            throw RegistryMiss::unpopulated($key, $this->declaration->root);
        }

        $exact = $this->recordsAt($key);
        $visible = $this->visible($exact);

        if (count($visible) === 1) {
            return $visible[0]['entry'];
        }

        if (count($visible) > 1) {
            throw RegistryMiss::ambiguous($key, $this->candidates($visible));
        }

        // Nothing at the key itself. A key naming a BRANCH is ambiguous rather than absent — several
        // entries live under it and none of them is what was asked for. Reported before the absent
        // case so the message can say so.
        $below = $this->visible($this->recordsUnder($key));

        if ($below !== []) {
            throw RegistryMiss::ambiguous($key, $this->candidates($below));
        }

        if ($exact !== [] || $this->recordsUnder($key) !== []) {
            throw RegistryMiss::filtered($key);
        }

        throw RegistryMiss::absent($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        try {
            return $this->resolve($key);
        } catch (AmbiguousRegistryMatch $ambiguous) {
            // Deliberately NOT swallowed — see Registry::tryResolve(). A miss is a question with no
            // answer; ambiguity is a question with several, and null here would be the null-to-empty
            // this exception family exists to refuse.
            throw $ambiguous;
        } catch (RegistryMiss) {
            return null;
        }
    }

    public function matches(RegistryKey|string $key): array
    {
        $key = (string) Key::of($key);

        $matched = $this->visible(array_merge($this->recordsAt($key), $this->recordsUnder($key)));

        usort($matched, fn (array $a, array $b) => $a['sequence'] <=> $b['sequence']);

        return array_values(array_map(fn (array $record) => $record['entry'], $matched));
    }

    public function keys(): array
    {
        $keys = [];

        foreach ($this->visible($this->entries) as $record) {
            $keys[$record['key']] = true;
        }

        return array_values(array_map(fn (string $key) => Key::parse($key), array_keys($keys)));
    }

    public function unfiltered(): Registry
    {
        $unfiltered = clone $this;
        $unfiltered->authorizer = null;

        return $unfiltered;
    }

    public function forget(RegistryKey|string $key): static
    {
        $key = (string) Key::of($key);

        $this->entries = array_values(array_filter(
            $this->entries,
            fn (array $record) => $record['key'] !== $key,
        ));

        // Total, not tidy: a surviving supersession record for a tenant-projected entry IS the
        // cross-tenant leak the teardown exists to prevent (ticket 08 D9).
        unset($this->superseded[$key]);

        return $this;
    }

    public function forgetBy(string $registrant): static
    {
        $this->entries = array_values(array_filter(
            $this->entries,
            fn (array $record) => $record['by'] !== $registrant,
        ));

        foreach ($this->superseded as $key => $displaced) {
            $kept = array_values(array_filter($displaced, fn (Superseded $s) => $s->by !== $registrant));

            if ($kept === []) {
                unset($this->superseded[$key]);

                continue;
            }

            $this->superseded[$key] = $kept;
        }

        return $this;
    }

    public function superseded(RegistryKey|string $key): array
    {
        return $this->superseded[(string) Key::of($key)] ?? [];
    }

    public function children(RegistryKey|string $key): array
    {
        $key = Key::of($key);
        $depth = count($key->segments()) + 1;

        return $this->branchKeys($key, fn (RegistryKey $below) => count($below->segments()) === $depth);
    }

    public function descendants(RegistryKey|string $key): array
    {
        return $this->branchKeys(Key::of($key), fn (RegistryKey $below) => true);
    }

    /**
     * The keys of every visible entry strictly under `$key`, truncated to whatever `$keep` accepts and
     * de-duplicated in registration order. A branch key is reported even when nothing is registered at
     * the branch itself — `a.b.c` makes `a.b` a child of `a`.
     *
     * @param  callable(RegistryKey): bool  $keep
     * @return list<RegistryKey>
     */
    private function branchKeys(RegistryKey $key, callable $keep): array
    {
        $depth = count($key->segments());
        $found = [];

        foreach ($this->visible($this->recordsUnder((string) $key)) as $record) {
            $segments = Key::parse($record['key'])->segments();

            for ($take = $depth + 1; $take <= count($segments); $take++) {
                $candidate = Key::fromSegments(array_slice($segments, 0, $take));

                if ($keep($candidate)) {
                    $found[(string) $candidate] = $candidate;
                }
            }
        }

        return array_values($found);
    }

    /** @return array{key: string, entry: mixed, by: string|null, ability: string|null, sequence: int}|null */
    private function recordAt(string $key): ?array
    {
        return $this->recordsAt($key)[0] ?? null;
    }

    /** @return list<array{key: string, entry: mixed, by: string|null, ability: string|null, sequence: int}> */
    private function recordsAt(string $key): array
    {
        return array_values(array_filter($this->entries, fn (array $record) => $record['key'] === $key));
    }

    /**
     * Segment-wise, never character-wise: `beam.realms` is not under `beam.realm`.
     *
     * @return list<array{key: string, entry: mixed, by: string|null, ability: string|null, sequence: int}>
     */
    private function recordsUnder(string $key): array
    {
        $prefix = Key::parse($key);

        return array_values(array_filter(
            $this->entries,
            fn (array $record) => Key::parse($record['key'])->isUnder($prefix),
        ));
    }

    /**
     * The authorization filter, applied identically by all five reads.
     *
     * An entry that declared no ability short-circuits BEFORE the authorizer is consulted — which is
     * what makes installing an authorizer incapable of narrowing an already-open surface (ticket 09 D2),
     * and why {@see Authorizer::allows()} can take a non-nullable ability.
     *
     * @param  list<array{key: string, entry: mixed, by: string|null, ability: string|null, sequence: int}>  $records
     * @return list<array{key: string, entry: mixed, by: string|null, ability: string|null, sequence: int}>
     */
    private function visible(array $records): array
    {
        if ($this->authorizer === null) {
            return array_values($records);
        }

        return array_values(array_filter($records, function (array $record) {
            if ($record['ability'] === null) {
                return true;
            }

            return $this->authorizer->allows($record['ability'], Key::parse($record['key']));
        }));
    }

    /** @return list<array{key: string, entry: mixed, by: string|null, ability: string|null, sequence: int}> */
    private function visibleAt(string $key): array
    {
        return $this->visible($this->recordsAt($key));
    }

    /**
     * @param  list<array{key: string, entry: mixed, by: string|null, ability: string|null, sequence: int}>  $records
     * @return list<array{key: string, by: string|null}>
     */
    private function candidates(array $records): array
    {
        return array_values(array_map(
            fn (array $record) => ['key' => $record['key'], 'by' => $record['by']],
            $records,
        ));
    }

    /** @param  array{key: string, entry: mixed, by: string|null, ability: string|null, sequence: int}  $displaced */
    private function supersede(array $displaced): void
    {
        $this->superseded[$displaced['key']][] = new Superseded(
            $displaced['key'],
            $displaced['entry'],
            $displaced['by'],
            $displaced['sequence'],
        );

        $this->entries = array_values(array_filter(
            $this->entries,
            fn (array $record) => $record['sequence'] !== $displaced['sequence'],
        ));
    }
}
