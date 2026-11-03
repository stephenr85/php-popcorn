<?php

namespace Rushing\Popcorn\Registries\Docs;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;

/**
 * The generated-facts block of a registry page: its renderer, its reader and the drift comparison
 * (registry-docs ticket 01, ruling 6).
 *
 * ## The marker contract, and where it comes from
 *
 * Ticket 03 defines the block. Its scaffold command writes the pages, and its
 * `Splicewire\Beam\Docs\Registries\RegistryFactsBlock` (in `splicewire/laravel-beam-docs`) was
 * written framework-free so it could be lifted here as-is. Ticket 04 lifted {@see START}, {@see END},
 * {@see render()} and {@see extract()} byte-for-byte, because the drift check passes only when both
 * sides render identical bytes. The writer-only parts (`stub()`, `replaceIn()`, the description
 * seed) stay with the scaffold command. **Once both tickets land, beam-docs' class should delegate
 * these four members here**, so that one renderer serves both the writer and the check.
 *
 * - The block opens with exactly {@see START} and closes with the first {@see END} after it. Both
 *   are MDX expression comments, because MDX 3 rejects `<!-- -->`.
 * - Between the markers is a CommonMark list of DECLARATION FACTS ONLY: the root, the entry
 *   contract, the duplicate policy, the population requirement, the order and the declaring class.
 *   The baked `description` is authorial input and is never a fact.
 * - **Drift means the page's block, markers included, is not byte-identical to a fresh
 *   {@see render()} of the declaration.** {@see drift()} names the differing lines.
 * - A page without {@see START} has no generated facts, and the check counts it as unchecked rather
 *   than drifted. {@see START} with no {@see END} after it, a second {@see START}, or an {@see END}
 *   with no {@see START} is malformed and fails the check.
 * - {@see START} names no command or product: whatever tool writes the block, the marker only says
 *   the block is generated. Only the exact current {@see START} is recognised. A block that still
 *   opens with an earlier wording reads as an end marker with no start, so it fails until it is
 *   regenerated; no superseded wording is accepted.
 *
 * This class renders and reads strings. It never writes a file.
 */
final class RegistryFacts
{
    public const START = '{/* registry-facts:start — generated from the #[IsRegistry] declaration; regenerate it, do not edit between these markers. */}';

    public const END = '{/* registry-facts:end */}';

    /** The block, markers included, for one declaration. Lifted verbatim from ticket 03's `RegistryFactsBlock::render()`. */
    public static function render(IsRegistry $declaration, string $declaringClass): string
    {
        $rows = [
            ['Root', self::code($declaration->root)],
            ['Entries', $declaration->entryType === 'mixed' ? 'any value (`mixed`)' : self::code(ltrim($declaration->entryType, '\\'))],
            ['Duplicate keys', self::duplicates($declaration->onKeyDuplicate)],
            ['Population', self::population($declaration->populationRequirement)],
            ['Order', self::code((string) $declaration->order).' (display order, ascending)'],
            ['Declared by', self::code(ltrim($declaringClass, '\\'))],
        ];

        // A CommonMark list, not a GFM table: the hosts' MDX pipelines run no remark-gfm, so a table
        // would render as a paragraph of pipes.
        $lines = [self::START, ''];

        foreach ($rows as [$fact, $value]) {
            $lines[] = "- **{$fact}:** {$value}";
        }

        $lines[] = '';
        $lines[] = self::END;

        return implode("\n", $lines);
    }

    /** The block as it stands in `$contents`, markers included, or null. Lifted verbatim from ticket 03's `RegistryFactsBlock::extract()`. */
    public static function extract(string $contents): ?string
    {
        $start = strpos($contents, self::START);
        $end = $start === false ? false : strpos($contents, self::END, $start);

        return $start === false || $end === false ? null : substr($contents, $start, $end + strlen(self::END) - $start);
    }

    /**
     * Read a page for the drift check: absent, malformed (and why), or present with its block.
     *
     * @return array{state: 'absent'}|array{state: 'malformed', error: string}|array{state: 'present', block: string}
     */
    public static function read(string $contents): array
    {
        $starts = substr_count($contents, self::START);
        $ends = substr_count($contents, self::END);

        if ($starts === 0 && $ends === 0) {
            return ['state' => 'absent'];
        }

        if ($starts !== 1 || $ends !== 1) {
            return ['state' => 'malformed', 'error' => "expected exactly one start and one end marker, found {$starts} start and {$ends} end"];
        }

        $block = self::extract($contents);

        return $block === null
            ? ['state' => 'malformed', 'error' => 'the end marker comes before the start marker']
            : ['state' => 'present', 'block' => $block];
    }

    /**
     * How a page's block differs from the fresh one, one line per differing line. Empty when the two
     * are byte-identical.
     *
     * @return list<string>
     */
    public static function drift(string $page, string $fresh): array
    {
        if ($page === $fresh) {
            return [];
        }

        $pageLines = explode("\n", $page);
        $freshLines = explode("\n", $fresh);
        $lines = [];

        foreach (array_diff($freshLines, $pageLines) as $line) {
            $lines[] = "the declaration renders `{$line}`, which the page lacks";
        }

        foreach (array_diff($pageLines, $freshLines) as $line) {
            $lines[] = "the page has `{$line}`, which the declaration does not render";
        }

        return $lines === [] ? ['the block differs from a fresh rendering in whitespace or line order'] : $lines;
    }

    private static function duplicates(OnKeyDuplicate $policy): string
    {
        return match ($policy) {
            OnKeyDuplicate::Supersede => 'Supersede — a later registration under a taken key replaces the earlier entry, in its place',
            OnKeyDuplicate::Reject => 'Reject — registering a taken key throws',
            OnKeyDuplicate::Admit => 'Admit — every registration is kept; a single-entry read of a shared key refuses as ambiguous',
        };
    }

    private static function population(PopulationRequirement $requirement): string
    {
        return match ($requirement) {
            PopulationRequirement::Required => 'Required — an empty registry is reported as unpopulated',
            PopulationRequirement::Optional => 'Optional — an empty registry is normal',
        };
    }

    private static function code(string $text): string
    {
        return '`'.str_replace('`', "'", $text).'`';
    }
}
