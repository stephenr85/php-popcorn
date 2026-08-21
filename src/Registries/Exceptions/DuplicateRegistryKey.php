<?php

namespace Rushing\Popcorn\Registries\Exceptions;

use RuntimeException;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * `register()` was offered a key that is already taken, on a registry declaring
 * {@see \Rushing\Popcorn\Registries\OnDuplicate::Reject}.
 *
 * NOT a {@see RegistryMiss} — nothing was missed; this is a write refused, at write time. It is a
 * sibling of {@see InvalidRegistryKey} under `RuntimeException`, following the per-family-base house
 * pattern the package already uses (`RunFailed` for the run family); there is no package-wide
 * `PopcornException`, and whether there should be is registry-kernel ticket 13's call.
 *
 * Names BOTH registrants, which is the whole point of refusing here rather than overwriting — the
 * message has to be enough to find the second one. It can do that only because every write carries
 * its registrant.
 */
class DuplicateRegistryKey extends RuntimeException
{
    public function __construct(
        public string $key,
        public ?string $incoming,
        public ?string $existing,
    ) {
        parent::__construct(sprintf(
            'An entry is already registered under `%s`%s; this registry rejects duplicates rather '
                .'than overwriting, so the incoming registration%s was refused. Two entries under one '
                .'key need two keys.',
            $key,
            $existing === null ? '' : " (by {$existing})",
            $incoming === null ? '' : " from {$incoming}",
        ));
    }

    public static function for(RegistryKey|string $key, ?string $incoming, ?string $existing): self
    {
        return new self((string) $key, $incoming, $existing);
    }
}
