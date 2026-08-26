<?php

namespace Rushing\Popcorn\Concerns;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * The reflection half of the trait-method chain — pure, static, and instantiating nothing.
 *
 * Split out from {@see ChainsTraitMethods} so the resolution can be asserted directly on a class-string
 * without constructing the object, and so a STATIC context (a boot chain that runs before an instance
 * exists, the way `Model::bootTraits()` does) can reach it at all. The trait is the ergonomics; this is
 * the mechanism, and it is the one under test.
 *
 * ## `using()` is not a reinvention of `class_uses_recursive()`
 *
 * Laravel ships exactly this walk as a global helper, and in a Laravel context that helper is the right
 * call. It is unusable HERE: `class_uses_recursive()` lives in `illuminate/support`, and this package
 * depends on `php` and `symfony/finder` and nothing else — the framework-free half of the popcorn split.
 * So this is an implementation of a known walk, not a competing one, and the two agree by construction:
 * traits of the class, traits of every parent, and traits of those traits, recursively.
 */
class TraitMethods
{
    /** The order a link takes when it declares none — the same default {@see \Rushing\Popcorn\Registries\IsRegistry} uses. */
    public const DEFAULT_ORDER = 100;

    /**
     * Every trait used by `$class`, its parents, and its traits' traits — declaration order, deduped.
     *
     * @param  class-string|object  $class
     * @return list<class-string>
     */
    public static function using(string|object $class): array
    {
        $class = is_object($class) ? $class::class : $class;

        $traits = [];

        foreach ([$class, ...array_values(class_parents($class) ?: [])] as $level) {
            foreach (class_uses($level) ?: [] as $trait) {
                static::collect($trait, $traits);
            }
        }

        return array_values($traits);
    }

    /**
     * The methods joining `$chain`, in a DETERMINISTIC order: ascending {@see Chained::$order}, ties
     * keeping discovery order — traits first, then any link the class itself declares.
     *
     * ⚠️ **Order is DECLARED, never positional.** The obvious design was trait `use` order, and it cannot
     * work in this estate: `vendor/bin/pint`'s Laravel preset includes `ordered_traits`, which sorts a
     * class's `use` statements alphabetically. It rewrote this mechanism's own test fixtures on the first
     * run. A chain relying on `use` order would be silently re-sequenced by a formatter, with nothing
     * failing — so `order:` carries it, on the same default (100) as `IsRegistry`.
     *
     * The tie-break still runs class-declared links last, so a chain whose steps are independent declares
     * nothing and a consuming class can still act on what its traits already did.
     *
     * A method is a link when it carries {@see Chained} naming this chain, OR when it is named
     * `{chain}{TraitBasename}` for a trait the class uses — the Eloquent convention, honoured so an
     * existing trait joins unchanged. A convention-named link takes the default order.
     *
     * @param  class-string|object  $class
     * @return list<ReflectionMethod> deduped by name, never two entries for one method
     */
    public static function in(string|object $class, string $chain): array
    {
        $reflection = new ReflectionClass(is_object($class) ? $class::class : $class);

        $conventional = [];

        foreach (static::using($class) as $trait) {
            $conventional[$chain.static::basename($trait)] = true;
        }

        $found = [];
        $discovered = 0;

        foreach ($reflection->getMethods() as $method) {
            if (isset($found[$method->getName()])) {
                continue;
            }

            $declared = static::declaredOrder($method, $chain);

            if ($declared === null && ! isset($conventional[$method->getName()])) {
                continue;
            }

            $found[$method->getName()] = [
                'method' => $method,
                'order' => $declared ?? static::DEFAULT_ORDER,
                // The tie-break: traits first, the composing class's own links last. A trait method's
                // `getDeclaringClass()` reports the COMPOSING class, so it cannot answer "did this arrive
                // through a trait" — the definition's file can, which is the one honest signal reflection
                // offers without parsing.
                'origin' => static::fromTrait($method, $class) ? 0 : 1,
                'discovered' => $discovered++,
            ];
        }

        $links = array_values($found);

        usort($links, fn (array $a, array $b) => [$a['order'], $a['origin'], $a['discovered']]
            <=> [$b['order'], $b['origin'], $b['discovered']]);

        return array_map(fn (array $link) => $link['method'], $links);
    }

    /**
     * `$method`'s declared order for `$chain` via {@see Chained}, or null when it declares no membership.
     *
     * Repeatable: one method may join several chains, so the attributes are scanned for the one naming
     * THIS chain rather than the first one found.
     */
    protected static function declaredOrder(ReflectionMethod $method, string $chain): ?int
    {
        foreach ($method->getAttributes(Chained::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $chained = $attribute->newInstance();

            if ($chained->chain === $chain) {
                return $chained->order;
            }
        }

        return null;
    }

    /**
     * Whether `$method` reached the class through one of its traits.
     *
     * `getDeclaringClass()` reports the COMPOSING class for a trait method, so it cannot answer this. The
     * file and line can: a trait method's definition lives in the trait's file, which is the one honest
     * signal reflection offers without parsing.
     */
    /** @param  class-string|object  $class */
    protected static function fromTrait(ReflectionMethod $method, string|object $class): bool
    {
        foreach (static::using($class) as $trait) {
            $reflection = new ReflectionClass($trait);

            if (! $reflection->hasMethod($method->getName())) {
                continue;
            }

            if ($reflection->getMethod($method->getName())->getFileName() === $method->getFileName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  class-string  $trait
     * @param  array<class-string, class-string>  $collected
     */
    protected static function collect(string $trait, array &$collected): void
    {
        if (isset($collected[$trait])) {
            return;
        }

        $collected[$trait] = $trait;

        foreach (class_uses($trait) ?: [] as $nested) {
            static::collect($nested, $collected);
        }
    }

    protected static function basename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
