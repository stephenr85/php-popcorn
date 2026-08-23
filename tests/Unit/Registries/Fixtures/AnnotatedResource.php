<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

/**
 * The one class under `Fixtures/` carrying {@see ScannedResource} — the scan's single hit, which is what
 * lets the registrar tests assert an exact key list rather than a subset.
 */
#[ScannedResource]
class AnnotatedResource
{
    public const KEY = 'invoices';
}
