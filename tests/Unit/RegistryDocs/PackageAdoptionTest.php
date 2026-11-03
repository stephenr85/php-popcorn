<?php

use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/*
 * php-popcorn adopts its own registry docs check (registry-docs ticket 04). The check is the
 * describe-gate ratchet: it stays inactive until this package grows a `docs/registries/` directory,
 * and from then on every root declared under `src/` (minus the empty root the index owns) must be covered.
 */
it('documents every registry root this package declares, once it opts in with docs/registries/', function () {
    $report = RegistryDocsCheck::forPackage(dirname(__DIR__, 3))->run();

    expect($report->failures())->toBe([], $report->summary());
});
