<?php

namespace Rushing\Popcorn\Registries\Docs;

/**
 * One package's committed docs manifest, `docs/registries/manifest.json` (registry-docs ticket 01,
 * amended rulings 3-5). A declared root without a conventional page is satisfied by this file, at both
 * audit tiers. It is plain JSON and this reader is framework-free: the package-tier check runs here in
 * `rushing/php-popcorn`, and `splicewire/laravel-beam-docs` reads the same file through this class.
 * Ticket 02 wrote the parser in beam-docs; ticket 04 moved it here so both tiers share one parser.
 *
 * Shape (every section optional; a package with only conventional pages ships no manifest at all):
 *
 * ```json
 * {
 *     "overrides": { "<root>": "docs/guides/the-page.mdx" },
 *     "external":  { "<root>": "https://github.com/vendor/name#anchor" },
 *     "exempt":    { "<root>": { "class": "rebind-only",
 *                                "reason": "hosts rebind this seam, nothing registers into it",
 *                                "pointer": "docs/adr/0001-why.md" } }
 * }
 * ```
 *
 * - `overrides` points a declared root at a non-default page path, relative to the package root.
 * - `external` satisfies a root whose page is hosted elsewhere. The value must be an http(s) URL.
 * - `exempt` entries need three things:
 *   - `class`, one of {@see ExemptionClass}. It is mandatory and never derived; the enum says why.
 *   - `reason`, mandatory and non-empty.
 *   - `pointer`, mandatory where {@see ExemptionClass::requiresPointer()}. It is a package-relative
 *     path or an http(s) URL; whether a path exists is the check's job, since parsing stays pure.
 * - A root appears in at most ONE section.
 * - The empty root `''` never appears: it is structurally skipped at every tier (ruling 3), so a key
 *   of `''` is dropped here before any consumer sees it.
 *
 * Errors are collected, never thrown: a malformed entry is left out of its section and named in
 * {@see $errors}, so a reader can still report on the rest.
 */
class RegistryDocsManifest
{
    public const FILENAME = 'manifest.json';

    /**
     * @param  array<string, string>  $overrides  root => package-relative page path
     * @param  array<string, string>  $external  root => http(s) URL
     * @param  array<string, array{class: string, reason: string, pointer: ?string}>  $exempt
     * @param  list<string>  $errors  shape violations, each naming the manifest and the key
     */
    final public function __construct(
        public readonly array $overrides = [],
        public readonly array $external = [],
        public readonly array $exempt = [],
        public readonly array $errors = [],
    ) {}

    /** Read `<dir>/manifest.json`, where `$dir` is the package's `docs/registries` directory. */
    public static function read(string $dir): static
    {
        $file = rtrim($dir, '/').'/'.self::FILENAME;

        if (! is_file($file)) {
            return new static;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (! is_array($decoded)) {
            return new static(errors: ["{$file}: not a JSON object"]);
        }

        $errors = [];
        $sections = [];

        foreach (['overrides', 'external', 'exempt'] as $section) {
            $sections[$section] = [];
            $raw = $decoded[$section] ?? [];

            if (! is_array($raw)) {
                $errors[] = "{$file}: `{$section}` must be an object of roots";

                continue;
            }

            foreach ($raw as $root => $value) {
                if (! is_string($root) || $root === '') {
                    continue; // the empty root is structurally skipped, never an error and never a row
                }
                $sections[$section][$root] = $value;
            }
        }

        $overrides = [];

        foreach ($sections['overrides'] as $root => $path) {
            is_string($path) && $path !== ''
                ? $overrides[$root] = $path
                : $errors[] = "{$file}: overrides.{$root} must be a package-relative path";
        }

        $external = [];

        foreach ($sections['external'] as $root => $url) {
            self::isUrl($url)
                ? $external[$root] = $url
                : $errors[] = "{$file}: external.{$root} must be an http(s) URL";
        }

        $exempt = [];

        foreach ($sections['exempt'] as $root => $entry) {
            $entry = is_array($entry) ? $entry : [];
            $reason = is_string($entry['reason'] ?? null) ? trim($entry['reason']) : '';
            $pointer = is_string($entry['pointer'] ?? null) && trim($entry['pointer']) !== '' ? trim($entry['pointer']) : null;
            $class = is_string($entry['class'] ?? null) ? ExemptionClass::tryFrom(trim($entry['class'])) : null;

            if ($reason === '') {
                $errors[] = "{$file}: exempt.{$root} must carry a non-empty `reason`";

                continue;
            }

            if ($class === null) {
                $errors[] = "{$file}: exempt.{$root} must name its `class`, one of ".implode(' | ', ExemptionClass::values());

                continue;
            }

            if ($pointer === null && $class->requiresPointer()) {
                $errors[] = "{$file}: exempt.{$root} is exempted as `{$class->value}` and must carry a `pointer` to the doc explaining the mechanism (ruling 4)";

                continue;
            }

            $exempt[$root] = ['class' => $class->value, 'reason' => $reason, 'pointer' => $pointer];
        }

        foreach (array_count_values([...array_keys($overrides), ...array_keys($external), ...array_keys($exempt)]) as $root => $count) {
            if ($count > 1) {
                $errors[] = "{$file}: `{$root}` appears in more than one section — a root has exactly one doc pointer";
            }
        }

        return new static($overrides, $external, $exempt, $errors);
    }

    /** Whether `$value` is a well-formed http(s) URL: the one test for both external pointers and URL exemption pointers. */
    public static function isUrl(mixed $value): bool
    {
        return is_string($value) && preg_match('#^https?://#', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /** @return list<string> every root this manifest names, each once */
    public function roots(): array
    {
        return array_values(array_unique([...array_keys($this->overrides), ...array_keys($this->external), ...array_keys($this->exempt)]));
    }
}
