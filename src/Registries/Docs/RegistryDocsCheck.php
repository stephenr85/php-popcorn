<?php

namespace Rushing\Popcorn\Registries\Docs;

/**
 * The package-tier registry documentation check (registry-docs ticket 01, amended ruling 5; built
 * by ticket 04). It is framework-free, so `rushing/php-popcorn`, which owns `#[IsRegistry]`, can run
 * it with no Laravel adapter, and so can any family package from its own `composer test`.
 *
 * It **activates only when the package has a `docs/registries/` directory**, the describe-gate
 * ratchet transposed (`splicewire/laravel-beam` particle doctrine: describing one registry opts a
 * package into describing all of them). A package without the directory gets an inactive report that
 * passes, so adopting the test costs nothing until the package opts in.
 *
 * Once active, it checks three things:
 *
 * - **Coverage.** Every root the package declares under its source paths, minus the empty root, is
 *   satisfied (see {@see PackageRegistryDocs} for the four shapes and their precedence).
 * - **Root membership.** Every root the directory names is declared by this package, both pages and
 *   manifest entries. Membership is checked against the package's own declaration scan, exactly and
 *   by root. `ExistsInRegistry` is never used: it answers whether an entry exists at a key, which is
 *   a different question (ruling 3).
 * - **Generated-facts drift.** Each documenting page that carries a facts block is compared with a
 *   fresh scan of the declaration ({@see RegistryFacts}).
 *
 * **It never writes.** Regenerating a page's facts is the scaffold command's job, and this is the
 * verification half (`docs/conventions/regenerating-committed-artifacts.md`: the test never writes).
 *
 * Adopting it is one test in the package's suite:
 *
 * ```php
 * it('documents every declared registry root', function () {
 *     expect(RegistryDocsCheck::forPackage(dirname(__DIR__))->run()->failures())->toBe([]);
 * });
 * ```
 *
 * @api
 */
final class RegistryDocsCheck
{
    /** @param  list<string>  $sourcePaths */
    public function __construct(
        private readonly PackageRegistryDocs $docs,
        private readonly array $sourcePaths,
    ) {}

    /**
     * The check for the package at `$packageRoot`. Its sources default to whichever of `src/` and
     * `app/` exist: `src/` for a package, and `app/` for a host app exempting its app-local roots
     * (ruling 4).
     *
     * @param  list<string>|null  $sourcePaths  absolute source paths, to override the default
     */
    public static function forPackage(string $packageRoot, ?array $sourcePaths = null): self
    {
        $root = rtrim($packageRoot, '/');

        return new self(
            new PackageRegistryDocs($root),
            $sourcePaths ?? array_values(array_filter([$root.'/src', $root.'/app'], 'is_dir')),
        );
    }

    public function run(): RegistryDocsReport
    {
        $package = $this->docs->packageRoot();

        if (! $this->docs->isActive()) {
            return RegistryDocsReport::inactive($package);
        }

        $census = new DeclaredRegistryRoots($this->sourcePaths);
        $declared = $census->declarations;
        $errors = $this->docs->problems();

        foreach ($census->unloadable as $class => $message) {
            $errors[] = "the declaration scan could not load {$class} ({$message}), so the population is incomplete";
        }

        foreach ($this->docs->manifest()->roots() as $root) {
            if (! isset($declared[$root])) {
                $errors[] = "manifest names `{$root}`, which no `#[IsRegistry]` in this package declares (dead root, or a pointer in the wrong package)";
            }
        }

        foreach ($this->docs->pages() as $root => $path) {
            // A CLUSTER page (registry-docs ticket 01, ruling 7; ticket 03): the filename is a
            // segment-wise prefix shared by roots this package declares, such as `beam.particle` over
            // `beam.particle.resources`. It documents the cluster, not a root, so it is no dead root.
            // Which package owns a cluster spanning packages is the catalog tier's to decide.
            if (! isset($declared[$root]) && self::isClusterOf($root, array_keys($declared))) {
                continue;
            }

            if (! isset($declared[$root])) {
                $errors[] = "{$path} names `{$root}`, which no `#[IsRegistry]` in this package declares (dead root; the filename is the dotted root, verbatim)";
            }
        }

        $coverage = [];
        $drift = [];
        $factsChecked = 0;

        foreach ($declared as $root => $declaration) {
            $coverage[$root] = $this->docs->satisfaction($root);
            $page = $this->docs->pagePath($root);

            if ($page === null) {
                continue;
            }

            $read = RegistryFacts::read((string) file_get_contents($this->docs->absolute($page)));

            if ($read['state'] === 'absent') {
                continue;
            }

            if ($read['state'] === 'malformed') {
                $errors[] = "{$page}: malformed facts block: {$read['error']}";

                continue;
            }

            $factsChecked++;

            foreach (RegistryFacts::drift($read['block'], RegistryFacts::render($declaration, $census->classes[$root])) as $line) {
                $drift[] = "{$page} ({$root}): facts drifted from the declaration: {$line}";
            }
        }

        return new RegistryDocsReport($package, true, $coverage, $errors, $drift, $factsChecked, $census->nonConforming);
    }

    /** @param  list<string|int>  $roots */
    private static function isClusterOf(string $prefix, array $roots): bool
    {
        foreach ($roots as $root) {
            if (str_starts_with((string) $root, $prefix.'.')) {
                return true;
            }
        }

        return false;
    }
}
