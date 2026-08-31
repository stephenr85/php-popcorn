<?php

namespace Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Unloadable;

/**
 * A class whose parent is not installed — the shape a family package's `src/Testing/` helper takes at a
 * host that does not carry that package's `require-dev`.
 *
 * Autoloading this raises `Error`, not `false`, which is what fataled the scanner before
 * registry-kernel 73 §1. Deliberately NOT in the `classes/` fixture directory the other tests scan
 * wholesale, so it breaks only the test that asks for it.
 */
class ExtendsMissingParent extends \Rushing\Popcorn\Tests\Absolutely\No\Such\ParentClass {}
