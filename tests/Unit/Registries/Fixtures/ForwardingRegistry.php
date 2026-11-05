<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\ForwardsToRegistryStore;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * A port in the shape the trait is for: a held `BasicRegistry`, its own `register()`, and nothing else.
 */
#[IsRegistry(
    root: 'popcorn.forwarding',
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    description: 'test entries, for the forwarding-trait suite',
)]
class ForwardingRegistry implements Gated, Registry
{
    use ForwardsToRegistryStore;

    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    protected function store(): BasicRegistry
    {
        return $this->entries;
    }
}
