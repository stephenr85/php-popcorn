<?php

namespace Rushing\Popcorn\Registries\Registrars;

use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;

/**
 * Fills a registry from an ALREADY-READ array of key => entry.
 *
 * ## Why it takes an array and not a config key
 *
 * Two reasons, and both are structural rather than stylistic.
 *
 * `rushing/php-popcorn` declares `package-topology.mustNotRequire: ["illuminate/*"]`, the mechanically
 * checked rule that guarantees the kernel's generality — so `config()` is not reachable from here at
 * all. `rushing/laravel-popcorn` ships the one thin binding that hands this class `config($key)`, which
 * is the whole of the Laravel side of this registrar (registry-kernel ticket 07 D6).
 *
 * The second reason outlives the first: **an array-in registrar ports to TypeScript; a `config()` call
 * does not.** Ticket 16's constraint on any TS Popcorn is that registrar output be reproducible from
 * declarative data, and a registrar that reaches into a host framework's runtime is the one shape that
 * cannot be.
 *
 * ## The array is flat, and the keys are relative
 *
 * `['invoices' => …, 'orders' => …]` — one level, keys as written. There is no nested-array flattening
 * step, because that would be the registrar deciding what a dotted key MEANS, and key grammar lives on
 * {@see \Rushing\Popcorn\Registries\Key} (ticket 05). A host that wants `beam.renderings.table` from a
 * nested config file flattens it before handing it over, in its own vocabulary.
 *
 * Keys go in relative and come out absolute: the registry stamps its own declared root at the door, so a
 * config file spells `invoices` and the entry is addressed `beam.particle.resources.invoices`
 * (ticket 20 D2).
 */
class ConfigRegistrar implements Registrar
{
    /**
     * @param  array<string, mixed>  $entries  key => entry, already read out of the host's config
     * @param  string  $configKey  where the host read it from, e.g. `beam.core.renderings` — this is
     *                             both the {@see source()} rendering and the `$by` on every write
     */
    public function __construct(
        private array $entries,
        private string $configKey,
    ) {}

    /**
     * @template TEntry
     *
     * @param  Registry<TEntry>  $registry
     */
    public function fill(Registry $registry): void
    {
        foreach ($this->entries as $key => $entry) {
            $registry->register($key, $entry, by: $this->configKey);
        }
    }

    /**
     * The config key, not the values — a registrant is the PLACE an entry came from, and naming the
     * place is what makes a last-wins auditable and a miss message actionable.
     */
    public function source(): string
    {
        return "config {$this->configKey}";
    }
}
