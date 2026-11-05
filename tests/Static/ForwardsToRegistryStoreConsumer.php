<?php

namespace Tests\Static;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\ForwardsToRegistryStore;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

class ForwardedWidget
{
    public function __construct(public string $name) {}
}

/**
 * ANALYSED, not run: the trait's one consumer inside the analysed paths, so phpstan checks the trait
 * rather than reporting it unused. The behaviour is pinned by
 * tests/Unit/Registries/ForwardsToRegistryStoreTest.php.
 *
 * `widget()` and `maybeWidget()` read through the TRAIT's forwarders, not the field. They fail at level 8
 * if the trait's methods stop inheriting `Registry<TEntry>`'s return types from this class's binding.
 *
 * @implements Registry<ForwardedWidget>
 */
#[IsRegistry(
    root: 'popcorn.static-fixture.forwarded',
    entryType: ForwardedWidget::class,
    onKeyDuplicate: OnKeyDuplicate::Reject,
    description: 'widgets, for the forwarding-trait fixture',
)]
class ForwardsToRegistryStoreConsumer implements Gated, Registry
{
    use ForwardsToRegistryStore;

    /** @var BasicRegistry<ForwardedWidget> */
    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /** @param  ForwardedWidget  $entry */
    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    public function widget(string $key): ForwardedWidget
    {
        return $this->resolve($key);
    }

    public function maybeWidget(string $key): ?ForwardedWidget
    {
        return $this->tryResolve($key);
    }

    /** @return BasicRegistry<ForwardedWidget> */
    protected function store(): BasicRegistry
    {
        return $this->entries;
    }
}
