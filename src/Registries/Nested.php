<?php

namespace Rushing\Popcorn\Registries;

/**
 * A registry that reads its keyspace as a TREE — enumeration, routing and display.
 *
 * Optional, and separate from {@see Registry} on purpose (registry-kernel ticket 05). A dotted key is a
 * real tree for those three jobs and **flat for resolution**: `resolve()` never walks up a key, and
 * `beam.realm.overlays.article` does not fall back to `beam.realm.overlays`. Where an entry genuinely
 * inherits, the registry DECLARES the chain — it is never inferred from how someone spelled a key.
 *
 * Both walks are segment-wise, never character-wise: `beam.realms` is not a child of `beam.realm`.
 */
interface Nested
{
    /**
     * The keys exactly one segment below `$key`, in registration order.
     *
     * A branch is not required to hold an entry of its own — `beam.realm.overlays.article` makes
     * `beam.realm.overlays` a child of `beam.realm` whether or not anything is registered there.
     *
     * @return list<RegistryKey>
     */
    public function children(RegistryKey|string $key): array;

    /**
     * Every key strictly below `$key` at any depth, in registration order.
     *
     * @return list<RegistryKey>
     */
    public function descendants(RegistryKey|string $key): array;
}
