<?php

use PHPUnit\Framework\TestCase;

uses(TestCase::class)->in('Unit');

/**
 * One section of the shared conformance corpus (registry-kernel ticket 05).
 *
 * It lives here rather than beside its first caller because it now has two: `KeyTest` drives `grammar`,
 * `equality` and `hierarchy`; `RegistryIndexTest` drives `routing` (ticket 16). A loader defined inside
 * one test file and called from another works only by Pest's file-load ordering, which is not a thing to
 * depend on.
 *
 * The corpus is JSON rather than inline datasets so that a TS port asserts against the SAME cases rather
 * than a second reading of the prose — drift between two runtimes is the entire risk it exists to
 * remove, and prose is where drift starts.
 */
function corpus(string $section): array
{
    static $decoded;

    $decoded ??= json_decode(
        file_get_contents(__DIR__.'/Fixtures/conformance/registry-key.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $decoded[$section];
}
