<?php

namespace Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Classes;

use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\ScanMarker;

// A `::class` fetch BEFORE the real declaration: a naive scanner keying off the bare `class`
// keyword misfires on the "class" token inside `ScanMarker::class` here too.
const PROSE_FIXTURE_MARKER = ScanMarker::class;

/**
 * Regression fixture: this docblock describes a first-class Capability on purpose — the phrase
 * "first-class Capability" contains the substring "class Capability", which a naive
 * `class\s+(\w+)` regex misreads as the type declaration itself (deriving the wrong FQCN).
 */
#[ScanMarker]
class ProseWithClassSubstring {}
