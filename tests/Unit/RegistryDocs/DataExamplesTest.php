<?php

use Rushing\Popcorn\Registries\Docs\DataExamples;

/*
 * The data-example convention (docs-feedback ticket 09). Each test builds a throwaway package holding one page and its
 * examples, so nothing here touches a committed fixture. The command half is driven through main() with an in-memory
 * stream; the verification half (problems, drift) is asserted never to write.
 */

/** @param  array<string, string>  $files  package-relative path => contents */
function dxPackage(array $files): string
{
    $root = sys_get_temp_dir().'/popcorn-data-examples-'.bin2hex(random_bytes(4));

    foreach ($files as $path => $contents) {
        is_dir($dir = dirname($root.'/'.$path)) || mkdir($dir, 0700, true);
        file_put_contents($root.'/'.$path, $contents);
    }

    $GLOBALS['dataExamplePackages'][] = $root;

    return $root;
}

/** A page with one empty marker pair for each name. */
function dxPage(string ...$names): string
{
    $body = "# Things\n\nProse before.\n\n";

    foreach ($names as $name) {
        $body .= DataExamples::start($name)."\n".DataExamples::end($name)."\n\nProse after {$name}.\n\n";
    }

    return $body;
}

/** @return array{0: int, 1: string} exit code and output */
function dxRun(string $root, array $args = [], ?Closure $fresh = null): array
{
    $out = fopen('php://memory', 'w+');
    $code = DataExamples::main(['docs:examples', ...$args], $root, $fresh ?? fn () => null, $out);
    rewind($out);

    return [$code, (string) stream_get_contents($out)];
}

function dxSnapshot(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
    }

    ksort($files);

    return $files;
}

afterEach(function () {
    foreach ($GLOBALS['dataExamplePackages'] ?? [] as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }

    $GLOBALS['dataExamplePackages'] = [];
});

const DX_PHP = "<?php\n\n// The registered things, resolved.\n\nreturn ['things.a' => ['weight' => 38.0], 'things.b' => ['weight' => 2]];\n";

it('discovers examples by page directory and name, sorted', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => dxPage('resolved', 'another'),
        'docs/registries/examples/things/resolved.php' => DX_PHP,
        'docs/registries/examples/things/another.php' => "<?php\nreturn 1;\n",
    ]);

    expect(DataExamples::forPackage($root)->cases())->toBe([
        'things/another' => 'docs/registries/examples/things/another.php',
        'things/resolved' => 'docs/registries/examples/things/resolved.php',
    ]);
});

it('encodes with the shared flags, keeping a float a float', function () {
    expect(DataExamples::encode(['weight' => 38.0, 'url' => 'https://x.test/a', 'name' => 'café']))
        ->toBe("{\n    \"weight\": 38.0,\n    \"url\": \"https://x.test/a\",\n    \"name\": \"café\"\n}\n");
});

it('renders the block from the source without its opener, and the captured JSON', function () {
    $root = dxPackage(['docs/registries/examples/things/resolved.php' => DX_PHP]);
    $examples = DataExamples::forPackage($root);

    expect($examples->block('things/resolved', $examples->capture('things/resolved')))->toBe(implode("\n", [
        DataExamples::start('resolved'),
        '',
        '<DataExample source="docs/registries/examples/things/resolved.php">',
        '',
        '```php',
        '// The registered things, resolved.',
        '',
        "return ['things.a' => ['weight' => 38.0], 'things.b' => ['weight' => 2]];",
        '```',
        '',
        '```json',
        '{',
        '    "things.a": {',
        '        "weight": 38.0',
        '    },',
        '    "things.b": {',
        '        "weight": 2',
        '    }',
        '}',
        '```',
        '',
        '</DataExample>',
        '',
        DataExamples::end('resolved'),
    ]));
});

it('reports a missing fixture, naming the regeneration command, and never writes', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => dxPage('resolved'),
        'docs/registries/examples/things/resolved.php' => DX_PHP,
    ]);
    $before = dxSnapshot($root);

    expect(DataExamples::forPackage($root)->problems())->toBe([
        "docs/registries/examples/things/resolved.json: missing or empty; regenerate with the package's `docs:examples` script (`--write`)",
    ])
        ->and(DataExamples::forPackage($root)->drift('things/resolved'))->toHaveCount(1)
        ->and(dxSnapshot($root))->toBe($before);
});

it('reports an empty fixture as missing', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => dxPage('resolved'),
        'docs/registries/examples/things/resolved.php' => DX_PHP,
        'docs/registries/examples/things/resolved.json' => "\n",
    ]);

    expect(DataExamples::forPackage($root)->problems()[0])->toContain('missing or empty');
});

it('prints what would change without --write, writes nothing, and exits 1', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => dxPage('resolved'),
        'docs/registries/examples/things/resolved.php' => DX_PHP,
    ]);
    $before = dxSnapshot($root);

    [$code, $output] = dxRun($root);

    expect($code)->toBe(1)
        ->and($output)->toContain('stale    things/resolved: docs/registries/examples/things/resolved.json, docs/registries/things.mdx')
        ->and($output)->toContain('+         "weight": 38.0')
        ->and($output)->toContain('re-run with --write')
        ->and(dxSnapshot($root))->toBe($before);
});

it('writes the fixture and the page block with --write, keeping every byte outside the markers', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => dxPage('resolved'),
        'docs/registries/examples/things/resolved.php' => DX_PHP,
    ]);
    $examples = DataExamples::forPackage($root);

    [$code, $output] = dxRun($root, ['--write']);
    $page = file_get_contents($root.'/docs/registries/things.mdx');

    expect($code)->toBe(0)
        ->and($output)->toContain('wrote    things/resolved')
        ->and($examples->fixture('things/resolved'))->toBe($examples->capture('things/resolved'))
        ->and($page)->toStartWith("# Things\n\nProse before.\n\n".DataExamples::start('resolved'))
        ->and($page)->toEndWith(DataExamples::end('resolved')."\n\nProse after resolved.\n\n")
        ->and($examples->problems())->toBe([])
        ->and($examples->drift('things/resolved'))->toBe([]);

    [$again, $second] = dxRun($root);

    expect($again)->toBe(0)->and($second)->toBe("current  things/resolved\n");
});

it('boots a fresh application once per example, before capturing it', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => dxPage('one', 'two'),
        'docs/registries/examples/things/one.php' => "<?php\nreturn \$GLOBALS['dxBoots'];\n",
        'docs/registries/examples/things/two.php' => "<?php\nreturn \$GLOBALS['dxBoots'];\n",
    ]);
    $GLOBALS['dxBoots'] = 0;

    dxRun($root, ['--write'], function () {
        $GLOBALS['dxBoots']++;
    });

    expect(DataExamples::forPackage($root)->fixture('things/one'))->toBe("1\n")
        ->and(DataExamples::forPackage($root)->fixture('things/two'))->toBe("2\n");
});

it('captures only the named examples', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => dxPage('one', 'two'),
        'docs/registries/examples/things/one.php' => "<?php\nreturn 1;\n",
        'docs/registries/examples/things/two.php' => "<?php\nreturn 2;\n",
    ]);

    dxRun($root, ['things/two', '--write']);

    expect(DataExamples::forPackage($root)->fixture('things/one'))->toBeNull()
        ->and(DataExamples::forPackage($root)->fixture('things/two'))->toBe("2\n");
});

it('has teeth: a changed example drifts from its fixture, and a hand-edited block fails', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => dxPage('resolved'),
        'docs/registries/examples/things/resolved.php' => DX_PHP,
    ]);
    dxRun($root, ['--write']);
    $examples = DataExamples::forPackage($root);

    // The registry changed: the example now returns something else, and the committed fixture no longer matches.
    file_put_contents($root.'/docs/registries/examples/things/resolved.php', str_replace('38.0', '39.0', DX_PHP));

    expect($examples->drift('things/resolved')[0])->toContain('a fresh capture differs from the committed fixture')
        ->and($examples->drift('things/resolved')[0])->toContain('+         "weight": 39.0')
        // The page shows the old source, so the block no longer equals a fresh rendering either.
        ->and($examples->problems())->toBe(["docs/registries/things.mdx (things/resolved): the page block differs from its source and fixture; regenerate with the package's `docs:examples` script (`--write`)"]);

    // Restore the source, then hand-edit the JSON on the page: only the block check catches it.
    file_put_contents($root.'/docs/registries/examples/things/resolved.php', DX_PHP);
    $page = $root.'/docs/registries/things.mdx';
    file_put_contents($page, str_replace('"weight": 2', '"weight": 3', file_get_contents($page)));

    expect($examples->drift('things/resolved'))->toBe([])
        ->and($examples->problems())->toHaveCount(1);
});

it('asks for the marker pair when a page lacks it, and refuses a duplicated pair', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => "# Things\n",
        'docs/registries/examples/things/resolved.php' => DX_PHP,
        'docs/registries/examples/things/resolved.json' => DataExamples::encode(1),
    ]);

    expect(DataExamples::forPackage($root)->problems()[0])->toStartWith('docs/registries/things.mdx: place the marker pair');

    [$code, $output] = dxRun($root, ['--write']);

    expect($code)->toBe(1)->and($output)->toContain('NO BLOCK things/resolved');

    file_put_contents($root.'/docs/registries/things.mdx', dxPage('resolved').dxPage('resolved'));

    expect(DataExamples::forPackage($root)->problems()[0])->toContain('expected one start and one end marker, found 2 and 2');
});

it('reports orphans: a fixture with no example, a block with no example, and a page that does not exist', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => dxPage('ghost'),
        'docs/registries/examples/things/stray.json' => DataExamples::encode(1),
        'docs/registries/examples/nowhere/lost.php' => "<?php\nreturn 1;\n",
        'docs/registries/examples/nowhere/lost.json' => DataExamples::encode(1),
    ]);

    expect(DataExamples::forPackage($root)->problems())->toBe([
        'docs/registries/examples/nowhere/lost.php: its page docs/registries/nowhere.mdx does not exist (the example directory is the page\'s filename)',
        'docs/registries/examples/things/stray.json: a fixture with no example beside it (delete it, or restore its .php)',
        'docs/registries/things.mdx: a data-example block names `ghost`, which has no docs/registries/examples/things/ghost.php',
    ]);
});

it('requires kebab-case example names', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => "# Things\n",
        'docs/registries/examples/things/Bad_Name.php' => "<?php\nreturn 1;\n",
    ]);

    expect(DataExamples::forPackage($root)->problems())->toBe([
        'docs/registries/examples/things/Bad_Name.php: an example name is kebab-case (`a-z`, `0-9`, single dashes)',
    ]);
});

it('reports a failing example by name and keeps going', function () {
    $root = dxPackage([
        'docs/registries/things.mdx' => dxPage('broken', 'fine'),
        'docs/registries/examples/things/broken.php' => "<?php\nthrow new RuntimeException('no registry bound');\n",
        'docs/registries/examples/things/fine.php' => "<?php\nreturn 1;\n",
    ]);

    [$code, $output] = dxRun($root, ['--write']);

    expect($code)->toBe(1)
        ->and($output)->toContain('FAILED   things/broken: RuntimeException: no registry bound')
        ->and($output)->toContain('wrote    things/fine');
});

it('is a clean no-op for a package with no examples', function () {
    $root = dxPackage(['docs/registries/things.mdx' => "# Things\n"]);

    expect(DataExamples::forPackage($root)->problems())->toBe([])
        ->and(dxRun($root)[0])->toBe(0);
});
