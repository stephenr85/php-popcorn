<?php

namespace Rushing\Popcorn\Registries;

/**
 * An ENTRY that knows its own key — the seam that makes one-argument registration possible without the
 * kernel guessing.
 *
 * On the entry, never on the registry. Three of the four exemplars self-key already
 * (`$invocable->name()`, `$schema['$id']`, `$resource->key`) and only Laravel's `Manager` passes a key
 * in, so the contract takes the EXPLICIT key and derives the sugar (registry-kernel ticket 01 D2). The
 * reverse — self-keying as the primitive — would force a wrapper class onto every array-valued and
 * scalar-valued registry in the estate.
 *
 * The return is a `RegistryKey|string` because an entry that already has a domain key should not have
 * to parse it to satisfy this; {@see Key::of()} coerces.
 */
interface HasRegistryKey
{
    public function registryKey(): RegistryKey|string;
}
