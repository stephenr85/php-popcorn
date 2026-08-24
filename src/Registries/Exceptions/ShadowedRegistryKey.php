<?php

namespace Rushing\Popcorn\Registries\Exceptions;

use RuntimeException;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * `describe()` was offered a root that would make an already-registered entry unreadable — one absolute
 * key with two answers, depending on which door a caller enters.
 *
 * ## Why nesting is legal and this is not
 *
 * Registry-kernel ticket 26 ruled INTERLEAVED ROOTS IN: `beam.particle` and `beam.particle.fragments.ops`
 * may both be described, and {@see \Rushing\Popcorn\Registries\RegistryIndex::routeTo()} sends every key
 * to the deepest root that claims it, unambiguously and with no kernel change — longest-prefix routing is
 * not defeated by nesting, nesting is what it is for.
 *
 * What routing cannot rescue is a key that exists on BOTH sides of the boundary. If `beam.particle`
 * already holds an entry at `beam.particle.fragments.ops.download` when the deeper registry is described,
 * `pop()` routes to the deeper one and never sees that entry again, while `$parent->has($key)` goes on
 * answering true. Nothing announces it. That is the map's recurring defect class in its purest form, and
 * ticket 26 D5 chose to make it loud rather than to define which of the two answers wins.
 *
 * ## What this does NOT outlaw
 *
 * Shadowing as a deliberate mechanism — `schemastud/php-json-ns`'s nearer-map-shadows-outer overlay, or
 * beam's tenant-cannot-shadow-fleet rule — is a policy a consumer implements inside its own keyspace.
 * The refusal here is narrower: two DESCRIBED registries may not both answer for one absolute key.
 *
 * A sibling of {@see DuplicateRegistryKey} under `RuntimeException`, per the package's
 * per-family-base house pattern; there is no package-wide `PopcornException` (ticket 13).
 */
class ShadowedRegistryKey extends RuntimeException
{
    /**
     * @param  string  $key  the entry key that would become unreachable
     * @param  string  $shallower  the root of the registry the key is spelled under
     * @param  string  $deeper  the root that would take ownership of it away
     */
    public function __construct(
        public string $key,
        public string $shallower,
        public string $deeper,
        public ?string $holder = null,
    ) {
        parent::__construct(sprintf(
            'Describing a registry at root `%s` would shadow the entry `%s`%s, which is already held by '
                .'the registry at root `%s`. Interleaved roots are legal and route by longest prefix, but '
                .'a key under both of them has two answers: `pop()` would reach the deeper registry while '
                .'the shallower one goes on reporting the entry as present. Move the entry, or move the '
                .'root.',
            $deeper,
            $key,
            $holder === null ? '' : " (registered by {$holder})",
            $shallower === '' ? '<the index root>' : $shallower,
        ));
    }

    public static function for(
        RegistryKey|string $key,
        RegistryKey|string $shallower,
        RegistryKey|string $deeper,
        ?string $holder = null,
    ): self {
        return new self((string) $key, (string) $shallower, (string) $deeper, $holder);
    }
}
