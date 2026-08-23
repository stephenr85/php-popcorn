<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Rushing\Popcorn\Registries\HasRegistryKey;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * What a `$project` callable hands back: the live estate shape, where a scanned class is reflected into a
 * declaration object that already knows its own key (`$resource->key`), so the registrar reads it off the
 * entry through {@see HasRegistryKey} rather than deriving one.
 */
class SelfKeyingEntry implements HasRegistryKey
{
    public function __construct(
        public string $key,
        public string $from,
    ) {}

    public function registryKey(): RegistryKey|string
    {
        return $this->key;
    }
}
