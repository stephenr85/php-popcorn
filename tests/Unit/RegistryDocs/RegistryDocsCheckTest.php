<?php

use Rushing\Popcorn\Registries\Docs\DeclaredRegistryRoots;
use Rushing\Popcorn\Registries\Docs\PackageRegistryDocs;
use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;
use Rushing\Popcorn\Registries\Docs\RegistryFacts;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Tests\Unit\RegistryDocs\Fixtures\AlphaRegistry;
use Rushing\Popcorn\Tests\Unit\RegistryDocs\Fixtures\NonConformingDeclaration;

/*
 * The package-tier registry docs check (registry-docs ticket 04). The declarations are the committed
 * fixtures under ./Fixtures (four conforming roots, one empty-root declarer, one non-conforming
 * declaration). Each test builds a throwaway package root holding only `docs/registries/` and points
 * the check's source path at the fixtures, so a test varies the docs and never the declarations.
 */

const RD_FIXTURE_ROOTS = ['docs.fixture.alpha', 'docs.fixture.beta', 'docs.fixture.delta', 'docs.fixture.gamma'];

/** @param  array<string, string>  $files  package-relative path => contents */
function rdPackage(array $files): string
{
    $root = sys_get_temp_dir().'/popcorn-registry-docs-'.bin2hex(random_bytes(4));

    foreach ($files as $path => $contents) {
        is_dir($dir = dirname($root.'/'.$path)) || mkdir($dir, 0700, true);
        file_put_contents($root.'/'.$path, $contents);
    }

    is_dir($root) || mkdir($root, 0700, true);
    $GLOBALS['registryDocsPackages'][] = $root;

    return $root;
}

/** Pages for the given roots. */
function rdPages(string ...$roots): array
{
    $files = [];

    foreach ($roots as $root) {
        $files["docs/registries/{$root}.mdx"] = "# {$root}\n";
    }

    return $files;
}

function rdManifest(array $manifest): array
{
    return ['docs/registries/manifest.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
}

function rdCheck(string $package): Rushing\Popcorn\Registries\Docs\RegistryDocsReport
{
    return RegistryDocsCheck::forPackage($package, [__DIR__.'/Fixtures'])->run();
}

afterEach(function () {
    foreach ($GLOBALS['registryDocsPackages'] ?? [] as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
    $GLOBALS['registryDocsPackages'] = [];
});

it('scans the declared population: conforming roots only, the empty root and non-conforming declarations outside it', function () {
    $census = new DeclaredRegistryRoots([__DIR__.'/Fixtures']);

    expect($census->roots())->toBe(RD_FIXTURE_ROOTS)
        ->and($census->roots())->not->toContain('')
        ->and($census->nonConforming)->toBe([NonConformingDeclaration::class])
        ->and($census->unloadable)->toBe([]);
});

it('is inactive and passes when the package has no docs/registries directory, however uncovered its roots are', function () {
    $report = rdCheck(rdPackage(['README.md' => "# a package\n"]));

    expect($report->active)->toBeFalse()
        ->and($report->passes())->toBeTrue()
        ->and($report->failures())->toBe([])
        ->and($report->summary())->toContain('inactive');
});

it('activates on an empty docs/registries directory and then fails every uncovered root by name', function () {
    $package = rdPackage([]);
    mkdir($package.'/docs/registries', 0700, true);

    $report = rdCheck($package);

    expect($report->active)->toBeTrue()
        ->and($report->passes())->toBeFalse()
        ->and($report->uncovered())->toBe(RD_FIXTURE_ROOTS)
        ->and(implode("\n", $report->failures()))->toContain('`docs.fixture.beta` is declared and uncovered');
});

it('is satisfied by every shape: conventional page, override, external URL and exemption', function () {
    $report = rdCheck(rdPackage([
        ...rdPages('docs.fixture.alpha'),
        'docs/guides/beta.mdx' => "# beta, placed elsewhere\n",
        'docs/adr/0001-why-gamma.md' => "# why\n",
        ...rdManifest([
            'overrides' => ['docs.fixture.beta' => 'docs/guides/beta.mdx'],
            'external' => ['docs.fixture.delta' => 'https://example.com/docs#delta'],
            'exempt' => ['docs.fixture.gamma' => ['class' => 'rebind-only', 'reason' => 'hosts rebind it; nothing registers', 'pointer' => 'docs/adr/0001-why-gamma.md']],
        ]),
    ]));

    expect($report->failures())->toBe([])
        ->and($report->coverage)->toBe([
            'docs.fixture.alpha' => PackageRegistryDocs::PAGE,
            'docs.fixture.beta' => PackageRegistryDocs::OVERRIDE,
            'docs.fixture.delta' => PackageRegistryDocs::EXTERNAL,
            'docs.fixture.gamma' => PackageRegistryDocs::EXEMPT,
        ])
        ->and($report->summary())->toContain('4 declared roots in this package (empty root excluded): 1 page · 1 override · 1 external · 1 exempt · 0 uncovered');
});

it('accepts an app-local exemption without a pointer, and a URL pointer for the classes that need one', function () {
    $report = rdCheck(rdPackage([
        ...rdPages('docs.fixture.alpha', 'docs.fixture.beta'),
        ...rdManifest(['exempt' => [
            'docs.fixture.gamma' => ['class' => 'app-local', 'reason' => 'this host declares it for itself'],
            'docs.fixture.delta' => ['class' => 'infrastructure', 'reason' => 'kernel plumbing', 'pointer' => 'https://example.com/foundations/registries'],
        ]]),
    ]));

    expect($report->failures())->toBe([]);
});

it('fails an override whose page does not exist, and the root stays uncovered', function () {
    $report = rdCheck(rdPackage([
        ...rdPages('docs.fixture.alpha', 'docs.fixture.gamma', 'docs.fixture.delta'),
        ...rdManifest(['overrides' => ['docs.fixture.beta' => 'docs/guides/missing.mdx']]),
    ]));

    expect($report->uncovered())->toBe(['docs.fixture.beta'])
        ->and(implode("\n", $report->failures()))->toContain('overrides.docs.fixture.beta points at `docs/guides/missing.mdx`, which does not exist');
});

it('fails a malformed external URL', function () {
    $report = rdCheck(rdPackage([
        ...rdPages('docs.fixture.alpha', 'docs.fixture.beta', 'docs.fixture.gamma'),
        ...rdManifest(['external' => ['docs.fixture.delta' => 'ftp://example.com/delta']]),
    ]));

    expect($report->uncovered())->toBe(['docs.fixture.delta'])
        ->and(implode("\n", $report->failures()))->toContain('external.docs.fixture.delta must be an http(s) URL');
});

it('fails an exemption missing its reason, its class, or the pointer its class requires', function (array $exemption, string $message) {
    $report = rdCheck(rdPackage([
        ...rdPages('docs.fixture.alpha', 'docs.fixture.beta', 'docs.fixture.delta'),
        ...rdManifest(['exempt' => ['docs.fixture.gamma' => $exemption]]),
    ]));

    expect($report->passes())->toBeFalse()
        ->and($report->uncovered())->toBe(['docs.fixture.gamma'])
        ->and(implode("\n", $report->failures()))->toContain($message);
})->with([
    'no reason' => [['class' => 'app-local'], 'exempt.docs.fixture.gamma must carry a non-empty `reason`'],
    'blank reason' => [['class' => 'app-local', 'reason' => '  '], 'exempt.docs.fixture.gamma must carry a non-empty `reason`'],
    'no class' => [['reason' => 'because', 'pointer' => 'https://example.com/why'], 'exempt.docs.fixture.gamma must name its `class`, one of infrastructure | rebind-only | app-local'],
    'unknown class' => [['class' => 'internal', 'reason' => 'because'], 'must name its `class`'],
    'rebind-only without pointer' => [['class' => 'rebind-only', 'reason' => 'hosts rebind it'], 'is exempted as `rebind-only` and must carry a `pointer`'],
    'infrastructure without pointer' => [['class' => 'infrastructure', 'reason' => 'plumbing'], 'is exempted as `infrastructure` and must carry a `pointer`'],
    'pointer to a missing file' => [['class' => 'rebind-only', 'reason' => 'hosts rebind it', 'pointer' => 'docs/adr/missing.md'], 'exempt.docs.fixture.gamma points at `docs/adr/missing.md`, which is neither an http(s) URL nor a file in the package'],
]);

it('structurally skips the empty root: never in the population, a manifest key of "" and a bare .mdx are both ignored', function () {
    $report = rdCheck(rdPackage([
        ...rdPages(...RD_FIXTURE_ROOTS),
        'docs/registries/.mdx' => "# the index\n",
        'docs/registries/manifest.json' => '{"exempt": {"": {"reason": ""}}}',
    ]));

    expect($report->failures())->toBe([])
        ->and(array_keys($report->coverage))->toBe(RD_FIXTURE_ROOTS)
        ->and(array_key_exists('', $report->coverage))->toBeFalse();
});

it('fails pages and manifest entries naming a root this package does not declare (exact root membership)', function () {
    $report = rdCheck(rdPackage([
        ...rdPages(...RD_FIXTURE_ROOTS),
        ...rdPages('docs.fixture.nonconforming'),
        ...rdPages('docs.elsewhere'), // a prefix of NO declared root is a dead root, not a cluster
        ...rdManifest(['external' => ['docs.fixture.alpha.entry' => 'https://example.com/x']]),
    ]));

    $failures = implode("\n", $report->failures());

    expect($report->uncovered())->toBe([])
        ->and($failures)->toContain('docs/registries/docs.elsewhere.mdx names `docs.elsewhere`, which no `#[IsRegistry]` in this package declares')
        ->and($failures)->toContain('names `docs.fixture.nonconforming`, which no `#[IsRegistry]`')
        ->and($failures)->toContain('manifest names `docs.fixture.alpha.entry`, which no `#[IsRegistry]` in this package declares');
});

it('accepts a cluster page named for a prefix of roots this package declares (ruling 7)', function () {
    $report = rdCheck(rdPackage([
        ...rdPages(...RD_FIXTURE_ROOTS),
        ...rdPages('docs.fixture'), // the cluster of every docs.fixture.* root
    ]));

    expect($report->failures())->toBe([])
        ->and(array_key_exists('docs.fixture', $report->coverage))->toBeFalse('a cluster is not a root');
});

function rdAlphaBlock(): string
{
    return RegistryFacts::render(IsRegistry::of(AlphaRegistry::class), AlphaRegistry::class);
}

it('renders the facts block byte-for-byte to the marker contract ticket 03 defined', function () {
    // Pinned literally: ticket 03's `RegistryFactsBlock::render()` must produce these same bytes for
    // this declaration, or the scaffold writes pages this check calls drifted.
    expect(rdAlphaBlock())->toBe(implode("\n", [
        '{/* registry-facts:start — generated from the #[IsRegistry] declaration; regenerate it, do not edit between these markers. */}',
        '',
        '- **Root:** `docs.fixture.alpha`',
        '- **Entries:** `string`',
        '- **Duplicate keys:** Reject — registering a taken key throws',
        '- **Population:** Required — an empty registry is reported as unpopulated',
        '- **Order:** `3` (display order, ascending)',
        '- **Declared by:** `Rushing\Popcorn\Tests\Unit\RegistryDocs\Fixtures\AlphaRegistry`',
        '',
        '{/* registry-facts:end */}',
    ]))->and(rdAlphaBlock())->not->toContain('authorial prose', 'the baked description is never a fact');
});

it('compares a page\'s facts block against a fresh rendering of the declaration: in step passes', function () {
    $report = rdCheck(rdPackage([
        ...rdPages('docs.fixture.beta', 'docs.fixture.gamma', 'docs.fixture.delta'),
        'docs/registries/docs.fixture.alpha.mdx' => "---\ntitle: alpha\n---\n\n# alpha\n\n".rdAlphaBlock()."\n\nProse the check never reads.\n",
    ]));

    expect($report->failures())->toBe([])
        ->and($report->factsChecked)->toBe(1);
});

it('detects drift between a page\'s facts and the declaration, on an override page too', function () {
    $stale = str_replace('- **Order:** `3`', '- **Order:** `2`', rdAlphaBlock());
    $beta = str_replace(
        ['docs.fixture.alpha', '`string`', 'Reject — registering a taken key throws', 'Required — an empty registry is reported as unpopulated', '`3`', 'AlphaRegistry'],
        ['docs.fixture.beta', 'any value (`mixed`)', 'Reject — registering a taken key throws', 'Optional — an empty registry is normal', '`100`', 'BetaRegistry'],
        rdAlphaBlock(),
    ); // Beta declares Supersede; this page still says Reject

    $report = rdCheck(rdPackage([
        ...rdPages('docs.fixture.gamma', 'docs.fixture.delta'),
        'docs/registries/docs.fixture.alpha.mdx' => $stale."\n",
        'docs/guides/beta.mdx' => $beta."\n",
        ...rdManifest(['overrides' => ['docs.fixture.beta' => 'docs/guides/beta.mdx']]),
    ]));

    expect($report->drift)->toBe([
        'docs/registries/docs.fixture.alpha.mdx (docs.fixture.alpha): facts drifted from the declaration: the declaration renders `- **Order:** `3` (display order, ascending)`, which the page lacks',
        'docs/registries/docs.fixture.alpha.mdx (docs.fixture.alpha): facts drifted from the declaration: the page has `- **Order:** `2` (display order, ascending)`, which the declaration does not render',
        'docs/guides/beta.mdx (docs.fixture.beta): facts drifted from the declaration: the declaration renders `- **Duplicate keys:** Supersede — a later registration under a taken key replaces the earlier entry, in its place`, which the page lacks',
        'docs/guides/beta.mdx (docs.fixture.beta): facts drifted from the declaration: the page has `- **Duplicate keys:** Reject — registering a taken key throws`, which the declaration does not render',
    ])
        ->and($report->passes())->toBeFalse()
        ->and($report->factsChecked)->toBe(2);
});

it('calls a block drifted when only its whitespace differs', function () {
    $report = rdCheck(rdPackage([
        ...rdPages('docs.fixture.beta', 'docs.fixture.gamma', 'docs.fixture.delta'),
        'docs/registries/docs.fixture.alpha.mdx' => str_replace("\n\n- **Root:**", "\n- **Root:**", rdAlphaBlock())."\n",
    ]));

    expect($report->drift)->toBe(['docs/registries/docs.fixture.alpha.mdx (docs.fixture.alpha): facts drifted from the declaration: the block differs from a fresh rendering in whitespace or line order']);
});

it('fails a malformed facts block and leaves a page without one unchecked', function (string $body, string $message) {
    $report = rdCheck(rdPackage([
        ...rdPages('docs.fixture.beta', 'docs.fixture.gamma', 'docs.fixture.delta'),
        'docs/registries/docs.fixture.alpha.mdx' => $body,
    ]));

    expect(implode("\n", $report->failures()))->toContain("docs/registries/docs.fixture.alpha.mdx: malformed facts block: {$message}")
        ->and($report->factsChecked)->toBe(0);
})->with([
    'start without end' => [RegistryFacts::START."\n- **Root:** `docs.fixture.alpha`\n", 'expected exactly one start and one end marker, found 1 start and 0 end'],
    'end without start' => ["- **Root:** `docs.fixture.alpha`\n".RegistryFacts::END."\n", 'expected exactly one start and one end marker, found 0 start and 1 end'],
    'two blocks' => [str_repeat(RegistryFacts::START."\n".RegistryFacts::END."\n", 2), 'expected exactly one start and one end marker, found 2 start and 2 end'],
    'end before start' => [RegistryFacts::END."\n".RegistryFacts::START."\n", 'the end marker comes before the start marker'],
    // An earlier wording of the start marker is not recognised: a block still carrying one fails
    // loudly until it is regenerated, rather than passing on a marker the renderer no longer writes.
    'superseded start marker' => ["{/* registry-facts:start — an earlier wording of the marker. */}\n\n- **Root:** `docs.fixture.alpha`\n\n".RegistryFacts::END."\n", 'expected exactly one start and one end marker, found 0 start and 1 end'],
]);

it('counts a page without a facts block as unchecked, not as drift', function () {
    $report = rdCheck(rdPackage(rdPages(...RD_FIXTURE_ROOTS)));

    expect($report->failures())->toBe([])->and($report->factsChecked)->toBe(0);
});

it('reads the rendered block back out of a page through extract() and read()', function () {
    $page = "intro\n\n".rdAlphaBlock()."\n\nprose";

    expect(RegistryFacts::extract($page))->toBe(rdAlphaBlock())
        ->and(RegistryFacts::read($page))->toBe(['state' => 'present', 'block' => rdAlphaBlock()])
        ->and(RegistryFacts::read('no block here'))->toBe(['state' => 'absent']);
});

it('never writes: the package tree is byte-identical after a failing run', function () {
    $package = rdPackage([
        'docs/registries/docs.fixture.alpha.mdx' => str_replace('`3`', '`4`', rdAlphaBlock())."\n",
        ...rdManifest(['exempt' => ['docs.fixture.gamma' => ['reason' => 'no class']]]),
    ]);
    $snapshot = fn (): array => array_map(
        fn (string $file): string => $file.':'.md5_file($file),
        array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS)), false), 'is_file'),
    );
    $before = $snapshot();

    expect(rdCheck($package)->passes())->toBeFalse()
        ->and($snapshot())->toBe($before);
});
