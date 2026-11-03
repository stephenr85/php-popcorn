<?php

namespace Rushing\Popcorn\Registries\Docs;

/**
 * What one {@see RegistryDocsCheck} run found in one package. Its scope is always that package's own
 * declarations. It is never a fleet or host count, and {@see summary()} says so.
 */
final class RegistryDocsReport
{
    /**
     * @param  array<string, ?string>  $coverage  declared root => its {@see PackageRegistryDocs} kind, null while uncovered
     * @param  list<string>  $errors  manifest shape, dead roots, missing override/pointer files, malformed facts blocks, scan blindness
     * @param  list<string>  $drift  facts lines that disagree with a fresh declaration scan
     * @param  int  $factsChecked  pages whose facts block was compared
     * @param  list<class-string>  $nonConforming  declared classes that are not a `Registry`, outside the population as in the bake
     */
    public function __construct(
        public readonly string $package,
        public readonly bool $active,
        public readonly array $coverage = [],
        public readonly array $errors = [],
        public readonly array $drift = [],
        public readonly int $factsChecked = 0,
        public readonly array $nonConforming = [],
    ) {}

    public static function inactive(string $package): self
    {
        return new self($package, false);
    }

    /** @return list<string> declared roots nothing satisfies */
    public function uncovered(): array
    {
        return array_keys(array_filter($this->coverage, fn (?string $kind): bool => $kind === null));
    }

    public function passes(): bool
    {
        return $this->failures() === [];
    }

    /** @return list<string> one line per failure, empty for a passing or inactive package */
    public function failures(): array
    {
        if (! $this->active) {
            return [];
        }

        return [
            ...$this->errors,
            ...array_map(fn (string $root): string => "`{$root}` is declared and uncovered: add docs/registries/{$root}.mdx, or a manifest override, external URL or exemption", $this->uncovered()),
            ...$this->drift,
        ];
    }

    public function summary(): string
    {
        if (! $this->active) {
            return "{$this->package}: inactive (no docs/registries/ directory, so the registry docs check has not been opted into).";
        }

        $kinds = array_count_values(array_map(fn (?string $kind): string => $kind ?? 'uncovered', $this->coverage));

        return sprintf(
            '%s: %d declared roots in this package (empty root excluded): %d page · %d override · %d external · %d exempt · %d uncovered; facts compared on %d page(s), %d drift line(s); %d other error(s).',
            $this->package,
            count($this->coverage),
            $kinds[PackageRegistryDocs::PAGE] ?? 0,
            $kinds[PackageRegistryDocs::OVERRIDE] ?? 0,
            $kinds[PackageRegistryDocs::EXTERNAL] ?? 0,
            $kinds[PackageRegistryDocs::EXEMPT] ?? 0,
            $kinds['uncovered'] ?? 0,
            $this->factsChecked,
            count($this->drift),
            count($this->errors),
        );
    }
}
