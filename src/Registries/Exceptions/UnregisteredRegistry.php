<?php

namespace Rushing\Popcorn\Registries\Exceptions;

use RuntimeException;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * A key was handed to the index and no declared root claims it — longest-prefix routing found no owner,
 * so there was no registry to ask (registry-kernel ticket 05 §7, shaped by ticket 13 D1).
 *
 * ## A SIBLING of {@see RegistryMiss}, not a subclass
 *
 * Deliberate, and the distinction is operational rather than taxonomic. A key naming no registry and a
 * key naming a registry with nothing at it are different errors with different fixes: the first is a
 * typo'd namespace or a provider that never described itself, the second is a typo'd leaf. Catching
 * `RegistryMiss` should not catch this, because a caller with a sensible fallback for "no such entry"
 * almost never has one for "no such registry".
 *
 * {@see AmbiguousRegistryMatch} is not the precedent for the other reading: it subclasses `RegistryMiss`
 * because an ambiguous match IS a failed read of the same registry. Here there is no registry.
 *
 * So this extends `RuntimeException` directly, beside {@see DuplicateRegistryKey} and
 * {@see InvalidRegistryKey}, following the per-family-base house pattern. There is no package-wide
 * `PopcornException` and no marker interface — ticket 13 D1 refused one on the grounds that a marker is
 * retrofittable at zero cost, so the speculative version buys nothing the deferred one does not.
 */
class UnregisteredRegistry extends RuntimeException
{
    /**
     * @param  list<string>  $roots  every declared root the walk considered, for the message
     */
    public function __construct(
        public string $key,
        public array $roots = [],
    ) {
        parent::__construct(sprintf(
            'No registry claims `%s` — no declared root is a prefix of it. %s',
            $key,
            $roots === []
                ? 'Nothing has described itself into the index at all, which is usually a service '
                    .'provider that did not boot rather than a bad key.'
                : 'Declared roots are: '.implode(', ', array_map(
                    static fn (string $root): string => $root === '' ? '`` (the index)' : "`{$root}`",
                    $roots,
                )).'.',
        ));
    }

    /**
     * @param  list<string>  $roots
     */
    public static function for(RegistryKey|string $key, array $roots = []): self
    {
        return new self((string) $key, $roots);
    }
}
