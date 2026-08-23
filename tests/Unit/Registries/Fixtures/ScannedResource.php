<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Attribute;

/** A stand-in for a consumer's own discovery attribute — `#[ParticleResource]`, `#[Realm]`, any of them. */
#[Attribute(Attribute::TARGET_CLASS)]
class ScannedResource {}
