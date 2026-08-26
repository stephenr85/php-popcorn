<?php

namespace Tests\Static;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;

/**
 * A STATIC fixture. Nothing here is executed and there is no assertion in it — it is analysed, and
 * the analysis IS the assertion.
 *
 * It exists because `@template TEntry` on {@see Registry} is a claim about consumers this repo has no
 * consumer to check: the kernel is framework-free, so nothing in `src/` writes
 * `extends Registry<Something>`. An annotation published to 95 potential readers and verified by none
 * of them is exactly the write-only shape this map objects to — so the reader lives here, in the
 * kernel's own analysed paths, and a wrong generic fails `composer analyse` here rather than in 95
 * hosts (registry-kernel ticket 50).
 *
 * Every method below is written so that it FAILS at level 8 if the generic stops flowing: each one
 * declares a concrete return type and hands back something the generic is the only reason to believe
 * is that type. Drop `TEntry` and `resolve()` infers `mixed`, and every one of them reports
 * `should return … but returns mixed`.
 *
 * `tests/Static/` is on `phpstan.neon`'s `paths`; the rest of `tests/` deliberately is not.
 */
class ResourceDefinition
{
    public function __construct(public string $name) {}
}

/**
 * The recipe registry-kernel ticket 34 D4 settled: a PORT names the entry type once, by extending the
 * kernel's interface with it bound. `entryType:` and `@template TEntry` are two halves of one fact —
 * the attribute is what the index, the doctor and the conformance audit read; the generic is what the
 * call site reads.
 *
 * @extends Registry<ResourceDefinition>
 */
interface ResourceRegistry extends Registry {}

/**
 * The concrete side of the sanctioned composition shape: the owner declares, and HOLDS a
 * {@see BasicRegistry} bound to the same type its declaration names.
 */
#[IsRegistry(
    root: 'popcorn.static-fixture.resources',
    of: 'resource definitions, for the generics fixture',
    arity: RegistryArity::PickOne,
    entryType: ResourceDefinition::class,
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
)]
class ComposedResourceRegistry
{
    /** @var BasicRegistry<ResourceDefinition> */
    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    public function add(ResourceDefinition $resource): static
    {
        $this->entries->register($resource->name, $resource, by: self::class);

        return $this;
    }

    /** The caller-facing sugar is typed by the field's binding — no cast, no inline `@var`. */
    public function get(string $key): ResourceDefinition
    {
        return $this->entries->resolve($key);
    }

    /** @return list<ResourceDefinition> */
    public function all(): array
    {
        return $this->entries->matches('');
    }
}

class GenericsAtTheCallSite
{
    public function resolveIsTyped(ResourceRegistry $registry): ResourceDefinition
    {
        return $registry->resolve('invoices');
    }

    /** @return list<ResourceDefinition> */
    public function matchesIsTyped(ResourceRegistry $registry): array
    {
        return $registry->matches('');
    }

    public function tryResolveIsTypedOrNull(ResourceRegistry $registry): ?ResourceDefinition
    {
        return $registry->tryResolve('invoices');
    }

    /**
     * `unfiltered()` keeps the binding — the escape hatch for the doctor and the surgeon gate must not
     * be the place a consumer silently drops back to `mixed`.
     */
    public function unfilteredKeepsTheBinding(ResourceRegistry $registry): ?ResourceDefinition
    {
        return $registry->unfiltered()->tryResolve('invoices');
    }

    /**
     * The variance question, answered by the analyser rather than by argument: a registry bound to a
     * CONCRETE entry type is still describable into the index, whose own binding is
     * `Registry<Registry<mixed>>`. If this were rejected, every typed port in the estate would have to
     * choose between a typed call site and being in the index — so it is checked here on purpose.
     */
    public function aTypedPortIsStillDescribable(RegistryIndex $index, ResourceRegistry $registry): RegistryIndex
    {
        return $index->describe($registry);
    }
}
