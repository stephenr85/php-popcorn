<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

/**
 * The shipped extension shape: a subclass that adds behaviour and declares NOTHING, expecting to keep
 * its parent's root. Before registry-kernel ticket 42 this could not be constructed at all — PHP does
 * not inherit class attributes, so `BasicRegistry::for()` read `static::class` and threw.
 */
class InheritingRegistry extends DeclaredRegistry {}
