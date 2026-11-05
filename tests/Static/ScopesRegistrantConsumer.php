<?php

namespace Tests\Static;

use Rushing\Popcorn\Registries\ScopesRegistrant;

/**
 * ANALYSED, not run: the trait's one consumer inside the analysed paths, in the hook shape it is for
 * (a parent owning `register()`/`boot()`), so phpstan checks the trait rather than skipping it as unused.
 * The behaviour is pinned by tests/Unit/Registries/RegistrantTest.php.
 */
abstract class HookedParent
{
    /** @return void */
    public function register() {}

    /** @return void */
    public function boot() {}
}

class ScopesRegistrantConsumer extends HookedParent
{
    use ScopesRegistrant;

    protected function registrant(): string
    {
        return 'acme/static-consumer';
    }
}
