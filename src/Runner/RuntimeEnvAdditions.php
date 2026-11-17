<?php

namespace Rushing\Popcorn\Runner;

/**
 * The CLOSED list of environment variables a child's OWN runtime has been observed to add after it started, per
 * runtime and platform (root ruling 44be5842, popcorn-runner-rehome 03; ADR-0003's amendment).
 *
 * A {@see Session} starts its child with exactly the Grant's environment: that is the delivery invariant, and nothing
 * here relaxes it. What a check reads, though, is usually what the child's own code sees (`getenv()` in a PHP child),
 * and a PHP child on macOS has been observed to see `__CF_USER_TEXT_ENCODING` there although it was not handed to it.
 * That is all this records: an OBSERVED post-exec runtime behaviour. It does not claim the platform injects the name at
 * spawn, and it does not attribute it to any library.
 *
 * The list is closed and explicit: one runtime, one platform, one name. Nothing is matched by pattern, a name the
 * Grant itself carries is never removed, and an entry joins only by a new ruling.
 *
 * @api
 */
final class RuntimeEnvAdditions
{
    /** runtime => PHP_OS_FAMILY => names */
    public const OBSERVED = [
        'php' => [
            'Darwin' => ['__CF_USER_TEXT_ENCODING'],
        ],
    ];

    /**
     * The names `$runtime` may add on `$osFamily`, from {@see OBSERVED}; empty for anything not listed.
     *
     * @return list<string>
     */
    public static function for(string $runtime, string $osFamily = PHP_OS_FAMILY): array
    {
        return self::OBSERVED[$runtime][$osFamily] ?? [];
    }

    /**
     * The environment a child reported, without the names its runtime may add on this platform, so a check can then
     * require exact equality with what was granted. A listed name the Grant carries is kept (it was delivered); every
     * other name is kept, so an extra one still fails the equality.
     *
     * @param  array<string, string>  $observed
     * @param  array<string, string>  $granted
     * @return array<string, string>
     */
    public static function withoutObserved(array $observed, array $granted, string $runtime, string $osFamily = PHP_OS_FAMILY): array
    {
        foreach (self::for($runtime, $osFamily) as $name) {
            if (! array_key_exists($name, $granted)) {
                unset($observed[$name]);
            }
        }

        return $observed;
    }
}
