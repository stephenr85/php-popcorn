<?php

namespace Rushing\Popcorn\Registries\Docs;

use Closure;
use JsonException;
use Throwable;

/**
 * The data examples on a package's registry pages: real output, captured from the PHP that produced it, committed beside
 * the page and shown on it, so an example can't drift from the code (docs-feedback ticket 09).
 *
 * ## The layout
 *
 * An example is one PHP file, `docs/registries/examples/<page>/<name>.php`, where `<page>` is the page's filename without
 * `.mdx` (a root, or a cluster page such as `schemas`) and `<name>` is kebab-case. The file `return`s a value: a
 * registry's resolved entries, or a derived object such as a dereferenced reference, a generated JSON Schema or a
 * catalog row. Its captured output is committed beside it as `<name>.json`, and the page carries both inside one
 * marker pair:
 *
 * ```mdx
 * {/* data-example:start <name> — captured output; regenerate it, do not edit between these markers. *}
 * …generated: a <DataExample> holding the PHP and the JSON as two fenced code blocks…
 * {/* data-example:end <name> *}
 * ```
 *
 * The author places the marker pair (it may start empty) and writes the prose around it. Everything between the markers
 * is generated from the two files, so the PHP a reader sees is exactly the file that ran.
 *
 * ## The two halves (`docs/conventions/regenerating-committed-artifacts.md`)
 *
 * - **Regeneration is a command.** {@see main()} backs a package's `docs:examples` script: it boots a fresh application
 *   per example (the caller's closure, normally the package's own test case), captures, and prints what would change.
 *   Only `--write` writes the fixture and the page block.
 * - **Verification is a test, and it never writes.** {@see problems()} is pure: every example has a fixture, every
 *   page block equals a fresh {@see block()} of its two files, and nothing is orphaned. {@see drift()} re-runs one
 *   example inside the test's application and compares the encoding byte for byte with the fixture.
 *
 * Both halves read the cases, the paths, the encoder and the block renderer from this one class. The comparison is of
 * encoded bytes, never of decoded values, so a JSON round-trip's scalar changes (`38.0` → `38`) can't hide on one side.
 *
 * An example must be deterministic: no minted ids, clocks or random values. A nondeterministic example shows up as drift.
 *
 * @api
 */
final class DataExamples
{
    public const DIRECTORY = 'docs/registries/examples';

    public const COMPONENT = 'DataExample';

    /** The encoding both halves use. Pretty, unescaped, and `38.0` stays `38.0`. */
    public const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private const NAME = '[a-z0-9]+(?:-[a-z0-9]+)*';

    private readonly string $root;

    public function __construct(string $packageRoot)
    {
        $this->root = rtrim($packageRoot, '/');
    }

    public static function forPackage(string $packageRoot): self
    {
        return new self($packageRoot);
    }

    public static function start(string $name): string
    {
        return "{/* data-example:start {$name} — captured output; regenerate it, do not edit between these markers. */}";
    }

    public static function end(string $name): string
    {
        return "{/* data-example:end {$name} */}";
    }

    /**
     * Every example, keyed `<page>/<name>`, sorted, with its package-relative PHP path.
     *
     * @return array<string, string>
     */
    public function cases(): array
    {
        $cases = [];

        foreach (glob($this->root.'/'.self::DIRECTORY.'/*/*.php') ?: [] as $file) {
            $id = basename(dirname($file)).'/'.basename($file, '.php');
            $cases[$id] = substr($file, strlen($this->root) + 1);
        }

        ksort($cases);

        return $cases;
    }

    /** The page an example belongs on, package-relative. */
    public static function pageOf(string $id): string
    {
        return PackageRegistryDocs::DIRECTORY.'/'.explode('/', $id, 2)[0].'.mdx';
    }

    public static function fixtureOf(string $id): string
    {
        return self::DIRECTORY.'/'.$id.'.json';
    }

    public static function nameOf(string $id): string
    {
        return explode('/', $id, 2)[1] ?? $id;
    }

    /** The shared encoder: the fixture's exact bytes for a value, newline-terminated. */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS)."\n";
    }

    /**
     * Run one example in the CURRENT application and encode what it returns. The caller boots the application: the test
     * already has one, and {@see main()} boots a fresh one per example.
     */
    public function capture(string $id): string
    {
        $path = $this->cases()[$id] ?? throw new \InvalidArgumentException("No data example `{$id}` under {$this->root}/".self::DIRECTORY.'.');
        $file = $this->root.'/'.$path;

        $value = (static fn (string $__file): mixed => require $__file)($file);

        return self::encode($value);
    }

    /** The PHP a reader sees: the file, without its `<?php` opener. */
    public function source(string $id): string
    {
        $path = $this->cases()[$id] ?? throw new \InvalidArgumentException("No data example `{$id}`.");
        $php = (string) file_get_contents($this->root.'/'.$path);

        return trim((string) preg_replace('/\A<\?php\s*/', '', $php));
    }

    /** The generated page block for an example, markers included, from its source and the given captured JSON. */
    public function block(string $id, string $json): string
    {
        $name = self::nameOf($id);

        return implode("\n", [
            self::start($name),
            '',
            '<'.self::COMPONENT.' source="'.$this->cases()[$id].'">',
            '',
            '```php',
            $this->source($id),
            '```',
            '',
            '```json',
            rtrim($json, "\n"),
            '```',
            '',
            '</'.self::COMPONENT.'>',
            '',
            self::end($name),
        ]);
    }

    /** The committed fixture's bytes, or null when it is missing or empty. */
    public function fixture(string $id): ?string
    {
        $path = $this->root.'/'.self::fixtureOf($id);
        $json = is_file($path) ? (string) file_get_contents($path) : '';

        return trim($json) === '' ? null : $json;
    }

    /**
     * The verification that needs no application: fixtures present and valid, page blocks equal to a fresh rendering of
     * their two files, and nothing orphaned. Empty when all is well.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $cases = $this->cases();
        $regenerate = "regenerate with the package's `docs:examples` script (`--write`)";

        foreach ($cases as $id => $path) {
            if (! preg_match('/\A'.self::NAME.'\z/', self::nameOf($id))) {
                $problems[] = "{$path}: an example name is kebab-case (`a-z`, `0-9`, single dashes)";

                continue;
            }

            $fixture = $this->fixture($id);

            if ($fixture === null) {
                $problems[] = self::fixtureOf($id).": missing or empty; {$regenerate}";

                continue;
            }

            try {
                json_decode($fixture, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $problems[] = self::fixtureOf($id).": not JSON ({$e->getMessage()}); {$regenerate}";

                continue;
            }

            $page = self::pageOf($id);

            if (! is_file($this->root.'/'.$page)) {
                $problems[] = "{$path}: its page {$page} does not exist (the example directory is the page's filename)";

                continue;
            }

            $read = self::read((string) file_get_contents($this->root.'/'.$page), self::nameOf($id));

            if ($read['state'] !== 'present') {
                $problems[] = "{$page}: ".($read['state'] === 'absent'
                    ? 'place the marker pair `'.self::start(self::nameOf($id)).'` … `'.self::end(self::nameOf($id))."`, then {$regenerate}"
                    : $read['error']);

                continue;
            }

            if ($read['block'] !== $this->block($id, $fixture)) {
                $problems[] = "{$page} ({$id}): the page block differs from its source and fixture; {$regenerate}";
            }
        }

        foreach (glob($this->root.'/'.self::DIRECTORY.'/*/*.json') ?: [] as $json) {
            $id = basename(dirname($json)).'/'.basename($json, '.json');

            if (! isset($cases[$id])) {
                $problems[] = self::fixtureOf($id).': a fixture with no example beside it (delete it, or restore its .php)';
            }
        }

        foreach (glob($this->root.'/'.PackageRegistryDocs::DIRECTORY.'/*.mdx') ?: [] as $page) {
            preg_match_all('/\{\/\* data-example:start ('.self::NAME.') /', (string) file_get_contents($page), $found);

            foreach ($found[1] as $name) {
                $id = basename($page, '.mdx').'/'.$name;

                if (! isset($cases[$id])) {
                    $problems[] = PackageRegistryDocs::DIRECTORY.'/'.basename($page).": a data-example block names `{$name}`, which has no ".self::DIRECTORY."/{$id}.php";
                }
            }
        }

        return $problems;
    }

    /**
     * Re-run one example in the current application and compare it with its fixture, byte for byte. Empty when they
     * agree. Never writes.
     *
     * @return list<string>
     */
    public function drift(string $id): array
    {
        $fixture = $this->fixture($id);

        if ($fixture === null) {
            return [self::fixtureOf($id).": missing or empty; regenerate with the package's `docs:examples` script (`--write`)"];
        }

        $fresh = $this->capture($id);

        return $fresh === $fixture ? [] : [self::fixtureOf($id).": a fresh capture differs from the committed fixture:\n".self::diff($fixture, $fresh)];
    }

    /**
     * Find one example's block in a page.
     *
     * @return array{state: 'absent'}|array{state: 'malformed', error: string}|array{state: 'present', block: string}
     */
    public static function read(string $contents, string $name): array
    {
        $start = self::start($name);
        $end = self::end($name);
        $starts = substr_count($contents, $start);
        $ends = substr_count($contents, $end);

        if ($starts === 0 && $ends === 0) {
            return ['state' => 'absent'];
        }

        if ($starts !== 1 || $ends !== 1) {
            return ['state' => 'malformed', 'error' => "data example `{$name}`: expected one start and one end marker, found {$starts} and {$ends}"];
        }

        $at = (int) strpos($contents, $start);
        $until = strpos($contents, $end, $at);

        return $until === false
            ? ['state' => 'malformed', 'error' => "data example `{$name}`: the end marker comes before the start marker"]
            : ['state' => 'present', 'block' => substr($contents, $at, $until + strlen($end) - $at)];
    }

    /** The page with one example's block replaced, or null when the page holds no well-formed marker pair for it. */
    public static function replaceIn(string $contents, string $name, string $block): ?string
    {
        $read = self::read($contents, $name);

        return $read['state'] === 'present' ? str_replace($read['block'], $block, $contents) : null;
    }

    /**
     * The `docs:examples` command: capture every example (or those named) in a fresh application each, and print what
     * would change. With `--write`, write the fixtures and page blocks. Exit 1 when something failed, or, without
     * `--write`, when something is out of date.
     *
     * @param  list<string>  $argv  `[script, ...ids, --write]`
     * @param  Closure(): mixed  $freshApplication  boots a fresh application for one example (the package's test case)
     * @param  resource|null  $out
     */
    public static function main(array $argv, string $packageRoot, Closure $freshApplication, $out = null): int
    {
        $out ??= STDOUT;
        $write = in_array('--write', $argv, true);
        $only = array_values(array_filter(array_slice($argv, 1), fn (string $arg) => ! str_starts_with($arg, '--')));
        $examples = new self($packageRoot);
        $cases = $examples->cases();
        $stale = 0;
        $failed = 0;

        if ($cases === []) {
            fwrite($out, 'No data examples under '.$packageRoot.'/'.self::DIRECTORY.".\n");

            return 0;
        }

        foreach ($cases as $id => $path) {
            if ($only !== [] && ! in_array($id, $only, true)) {
                continue;
            }

            try {
                $freshApplication();
                $json = $examples->capture($id);
            } catch (Throwable $e) {
                fwrite($out, "FAILED   {$id}: ".$e::class.': '.$e->getMessage()."\n");
                $failed++;

                continue;
            }

            $pagePath = $packageRoot.'/'.self::pageOf($id);
            $page = is_file($pagePath) ? (string) file_get_contents($pagePath) : null;
            $newPage = $page === null ? null : self::replaceIn($page, self::nameOf($id), $examples->block($id, $json));
            $fixtureChanged = $examples->fixture($id) !== $json;
            $pageChanged = $newPage !== null && $newPage !== $page;

            if ($newPage === null) {
                fwrite($out, "NO BLOCK {$id}: place `".self::start(self::nameOf($id)).'` … `'.self::end(self::nameOf($id)).'` in '.self::pageOf($id)."\n");
                $failed++;
            }

            if (! $fixtureChanged && ! $pageChanged) {
                fwrite($out, "current  {$id}\n");

                continue;
            }

            $stale++;

            if ($write) {
                $fixturePath = $packageRoot.'/'.self::fixtureOf($id);
                is_dir(dirname($fixturePath)) || mkdir(dirname($fixturePath), 0755, true);
                $fixtureChanged && file_put_contents($fixturePath, $json);
                $pageChanged && file_put_contents($pagePath, $newPage);
                fwrite($out, "wrote    {$id}: ".implode(', ', array_filter([$fixtureChanged ? self::fixtureOf($id) : null, $pageChanged ? self::pageOf($id) : null]))."\n");
            } else {
                fwrite($out, "stale    {$id}: ".implode(', ', array_filter([$fixtureChanged ? self::fixtureOf($id) : null, $pageChanged ? self::pageOf($id) : null]))."\n");
                $fixtureChanged && fwrite($out, self::diff($examples->fixture($id) ?? '', $json)."\n");
            }
        }

        if (! $write && $stale > 0) {
            fwrite($out, "{$stale} example(s) out of date. Review the output above, then re-run with --write.\n");
        }

        return $failed > 0 || (! $write && $stale > 0) ? 1 : 0;
    }

    /** A short line diff for review output: `-` the committed line, `+` the fresh one. */
    private static function diff(string $old, string $new): string
    {
        $oldLines = explode("\n", rtrim($old, "\n"));
        $newLines = explode("\n", rtrim($new, "\n"));
        $lines = [];

        foreach (array_diff($oldLines, $newLines) as $line) {
            $lines[] = '- '.$line;
        }

        foreach (array_diff($newLines, $oldLines) as $line) {
            $lines[] = '+ '.$line;
        }

        return $lines === [] ? '  (the same lines, in a different order or whitespace)' : implode("\n", array_slice($lines, 0, 40));
    }
}
