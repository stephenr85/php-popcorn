<?php

namespace Rushing\Popcorn\Registries\Docs;

use ReflectionClass;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registry;
use Symfony\Component\Finder\Finder;

/**
 * The `#[IsRegistry]` declarations under a package's source paths, as `root => declaration`. This is
 * the package-tier census the docs check validates root membership against (registry-docs ticket 01,
 * amended ruling 3).
 *
 * It applies the same population rules as `Rushing\Popcorn\Laravel\Baking\DeclaredRegistryScan`, the
 * estate census the catalog command reads, so a root the package tier counts is one the catalog
 * counts too:
 *
 * - **The source pre-filter.** A file is autoloaded only when its text names `IsRegistry`. The bake
 *   scan's docblock records why: autoloading an arbitrary class can die on an uncatchable missing
 *   trait.
 * - **Abstract classes and interfaces are skipped.** So is a declared class that does not implement
 *   {@see Registry}: it can never be described, and the bake counts it as non-conforming rather than
 *   baking it. Such classes are reported in {@see $nonConforming}, never silently dropped.
 * - **The empty root `''` is skipped by root.** Only the index declares it, and the index's
 *   documentation duty is discharged by the popcorn Foundations page (ruling 3).
 *
 * This is framework-free. It needs no container, no config and no bake; it reads files and reflects.
 */
final class DeclaredRegistryRoots
{
    /** @var array<string, IsRegistry> root => its declaration, root-sorted */
    public readonly array $declarations;

    /** @var array<string, class-string> root => the declaring class */
    public readonly array $classes;

    /** @var list<class-string> declared, but not a {@see Registry} */
    public readonly array $nonConforming;

    /** @var array<class-string, string> classes the scan met and could not load, with the raising message */
    public readonly array $unloadable;

    /** @param  list<string>  $paths  source directories (or files) to scan */
    public function __construct(array $paths)
    {
        $scanner = new AttributedClassScanner;
        $declarations = [];
        $classes = [];
        $nonConforming = [];

        foreach ($scanner->scan(self::candidateFiles($paths), IsRegistry::class, instanceof: false) as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (! $reflection->implementsInterface(Registry::class)) {
                $nonConforming[] = $class;

                continue;
            }

            $declaration = $reflection->getAttributes(IsRegistry::class)[0]->newInstance();

            if ($declaration->root === '') {
                continue;
            }

            $declarations[$declaration->root] = $declaration;
            $classes[$declaration->root] = $class;
        }

        ksort($declarations);
        ksort($classes);

        $this->declarations = $declarations;
        $this->classes = $classes;
        $this->nonConforming = $nonConforming;
        $this->unloadable = $scanner->unloadable();
    }

    /** @return list<string> the declared roots, sorted, the empty root never among them */
    public function roots(): array
    {
        return array_keys($this->declarations);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function candidateFiles(array $paths): array
    {
        $marker = (new ReflectionClass(IsRegistry::class))->getShortName();
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $candidates = [$path];
            } elseif (is_dir($path)) {
                $candidates = array_map(fn ($file): string => (string) $file->getRealPath(), iterator_to_array((new Finder)->files()->name('*.php')->in($path), false));
            } else {
                continue;
            }

            foreach ($candidates as $file) {
                if (str_contains((string) file_get_contents($file), $marker)) {
                    $files[] = $file;
                }
            }
        }

        return array_values(array_unique($files));
    }
}
