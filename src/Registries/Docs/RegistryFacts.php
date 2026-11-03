<?php

namespace Rushing\Popcorn\Registries\Docs;

use Rushing\Popcorn\Registries\IsRegistry;

/**
 * The generated-facts block of a registry page and its marker contract (registry-docs ticket 01,
 * ruling 6; contract defined by ticket 04, which builds the drift check).
 *
 * ## The marker contract
 *
 * A page carries at most one block, written as two MDX comments around one fenced `json` block:
 *
 * ````mdx
 * {/* registry-facts:begin (generated; regenerate, never hand-edit) *\/}
 * ```json
 * {
 *     "root": "beam.particle.resources",
 *     "entryType": "Splicewire\\Beam\\Particles\\ParticleResource",
 *     "onKeyDuplicate": "supersede",
 *     "populationRequirement": "optional",
 *     "order": 100
 * }
 * ```
 * {/* registry-facts:end *\/}
 * ````
 *
 * (`*\/` above is the docblock's escape for the comment's closing `*` and `/`; the page itself writes
 * them unescaped.)
 *
 * - The begin comment opens with `registry-facts:begin` and may carry trailing text. The end comment
 *   is exactly `registry-facts:end`.
 * - The JSON carries the {@see KEYS}: DECLARATION facts only, which are the root, the entry contract,
 *   the two policies (as enum values) and the order. The baked `description` is authorial input and
 *   is never a fact (ruling 6), so it is never in the block and never compared.
 * - Comparison is by decoded value, so key order and whitespace never count as drift. Missing keys,
 *   extra keys or changed values do.
 * - A page with no block has no generated facts to drift, and the check counts it as unchecked.
 *   A begin marker without an end, two blocks, or a block whose JSON does not decode is malformed
 *   and fails the check.
 *
 * {@see block()} renders the canonical text. A writer such as the scaffold command (ticket 03) renders
 * through it, so the writer and the check cannot disagree about the markers. This class only reads and
 * renders strings; it never writes a file.
 */
final class RegistryFacts
{
    public const BEGIN = 'registry-facts:begin';

    public const END = 'registry-facts:end';

    /** The fact keys, in the order {@see block()} renders them. */
    public const KEYS = ['root', 'entryType', 'onKeyDuplicate', 'populationRequirement', 'order'];

    /**
     * The fresh facts of one declaration.
     *
     * @return array{root: string, entryType: string, onKeyDuplicate: string, populationRequirement: string, order: int}
     */
    public static function of(IsRegistry $declaration): array
    {
        return [
            'root' => $declaration->root,
            'entryType' => $declaration->entryType,
            'onKeyDuplicate' => $declaration->onKeyDuplicate->value,
            'populationRequirement' => $declaration->populationRequirement->value,
            'order' => $declaration->order,
        ];
    }

    /**
     * The canonical block text for these facts, both markers included, with no trailing newline.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function block(array $facts): string
    {
        $ordered = [];

        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $facts)) {
                $ordered[$key] = $facts[$key];
            }
        }

        return '{/* '.self::BEGIN.' (generated; regenerate, never hand-edit) */}'."\n"
            ."```json\n"
            .json_encode($ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            ."```\n"
            .'{/* '.self::END.' */}';
    }

    /**
     * Read the block out of a page's text.
     *
     * @return array{state: 'absent'}|array{state: 'malformed', error: string}|array{state: 'present', facts: array<string, mixed>}
     */
    public static function extract(string $page): array
    {
        $begins = preg_match_all('#\{/\*\s*'.preg_quote(self::BEGIN, '#').'\b#', $page);
        $ends = preg_match_all('#\{/\*\s*'.preg_quote(self::END, '#').'\s*\*/\}#', $page);

        if ($begins === 0 && $ends === 0) {
            return ['state' => 'absent'];
        }

        if ($begins !== 1 || $ends !== 1) {
            return ['state' => 'malformed', 'error' => "expected exactly one begin and one end marker, found {$begins} begin and {$ends} end"];
        }

        $pattern = '#\{/\*\s*'.preg_quote(self::BEGIN, '#').'\b.*?\*/\}(.*?)\{/\*\s*'.preg_quote(self::END, '#').'\s*\*/\}#s';

        if (preg_match($pattern, $page, $block) !== 1) {
            return ['state' => 'malformed', 'error' => 'the end marker comes before the begin marker'];
        }

        if (preg_match('#```json[ \t]*\R(.*?)\R```#s', $block[1], $fence) !== 1) {
            return ['state' => 'malformed', 'error' => 'no fenced ```json block between the markers'];
        }

        $facts = json_decode($fence[1], true);

        if (! is_array($facts) || array_is_list($facts) && $facts !== []) {
            return ['state' => 'malformed', 'error' => 'the fenced block is not a JSON object'];
        }

        return ['state' => 'present', 'facts' => $facts];
    }

    /**
     * The keys whose values differ between a page's facts and the fresh ones, empty when in step.
     * Each line names the key and both values.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $fresh
     * @return list<string>
     */
    public static function drift(array $page, array $fresh): array
    {
        $lines = [];

        foreach (array_unique([...self::KEYS, ...array_keys($page)]) as $key) {
            $has = array_key_exists($key, $page);
            $expected = array_key_exists($key, $fresh);

            if ($has && ! $expected) {
                $lines[] = "`{$key}` is not a declaration fact (page says ".json_encode($page[$key]).')';
            } elseif (! $has && $expected) {
                $lines[] = "`{$key}` is missing (declaration says ".json_encode($fresh[$key]).')';
            } elseif ($has && $page[$key] !== $fresh[$key]) {
                $lines[] = "`{$key}`: page says ".json_encode($page[$key]).', declaration says '.json_encode($fresh[$key]);
            }
        }

        return $lines;
    }
}
