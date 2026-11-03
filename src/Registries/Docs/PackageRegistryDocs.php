<?php

namespace Rushing\Popcorn\Registries\Docs;

/**
 * One package's `docs/registries/` directory, read as coverage: which roots it satisfies, and how
 * (registry-docs ticket 01, amended rulings 3-5). Both audit tiers read it: the package-tier
 * {@see RegistryDocsCheck}, and beam-docs' host-tier doctor audit for each installed package.
 *
 * A root is satisfied by the first of these that holds, the same precedence the catalog command uses:
 *
 *  1. a manifest **override** whose path exists in the package;
 *  2. the **conventional page** `docs/registries/<root>.mdx`, the dotted root verbatim as the filename;
 *  3. a manifest **external** URL (well-formed http(s), checked by the parser);
 *  4. a manifest **exemption** with its class and reason, plus a pointer where the class requires
 *     one. A pointer is either a URL or a package-relative path, and a path must exist.
 *
 * An entry that fails its own test satisfies nothing. {@see problems()} names it, so the root reads
 * as uncovered and the reason is visible rather than hidden.
 *
 * This only reads.
 */
final class PackageRegistryDocs
{
    public const PAGE = 'page';

    public const OVERRIDE = 'override';

    public const EXTERNAL = 'external';

    public const EXEMPT = 'exempt';

    public const DIRECTORY = 'docs/registries';

    private ?RegistryDocsManifest $manifest = null;

    /** @var array<string, string>|null */
    private ?array $pages = null;

    private readonly string $root;

    public function __construct(string $packageRoot)
    {
        $this->root = rtrim($packageRoot, '/');
    }

    public function packageRoot(): string
    {
        return $this->root;
    }

    public function directory(): string
    {
        return $this->root.'/'.self::DIRECTORY;
    }

    /** Whether the package has opted in by having the directory (the ratchet: no directory, no check). */
    public function isActive(): bool
    {
        return is_dir($this->directory());
    }

    public function manifest(): RegistryDocsManifest
    {
        return $this->manifest ??= RegistryDocsManifest::read($this->directory());
    }

    /** @return array<string, string> root => package-relative path of its conventional page, root-sorted */
    public function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        $pages = [];

        foreach (glob($this->directory().'/*.mdx') ?: [] as $file) {
            $root = basename($file, '.mdx');

            if ($root !== '') { // `.mdx` would name the empty root, which is never in the population
                $pages[$root] = self::DIRECTORY.'/'.basename($file);
            }
        }

        ksort($pages);

        return $this->pages = $pages;
    }

    /** One of the kind constants for the way this package satisfies `$root`, or null where it does not. */
    public function satisfaction(string $root): ?string
    {
        if ($root === '') {
            return null;
        }

        $manifest = $this->manifest();

        return match (true) {
            isset($manifest->overrides[$root]) && $this->exists($manifest->overrides[$root]) => self::OVERRIDE,
            isset($this->pages()[$root]) => self::PAGE,
            isset($manifest->external[$root]) => self::EXTERNAL,
            isset($manifest->exempt[$root]) && $this->pointerHolds($manifest->exempt[$root]['pointer']) => self::EXEMPT,
            default => null,
        };
    }

    /** The package-relative page path that documents `$root`: the override where it exists, else the conventional page. */
    public function pagePath(string $root): ?string
    {
        return match ($this->satisfaction($root)) {
            self::OVERRIDE => $this->manifest()->overrides[$root],
            self::PAGE => $this->pages()[$root],
            default => null,
        };
    }

    /**
     * Everything wrong with the directory that does not depend on the declarations: the manifest's
     * own shape errors, overrides naming a missing file, and exemption pointers naming a missing file.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $manifest = $this->manifest();
        $problems = $manifest->errors;

        foreach ($manifest->overrides as $root => $path) {
            if (! $this->exists($path)) {
                $problems[] = "overrides.{$root} points at `{$path}`, which does not exist in the package";
            }
        }

        foreach ($manifest->exempt as $root => $exemption) {
            if (! $this->pointerHolds($exemption['pointer'])) {
                $problems[] = "exempt.{$root} points at `{$exemption['pointer']}`, which is neither an http(s) URL nor a file in the package";
            }
        }

        return $problems;
    }

    public function absolute(string $relative): string
    {
        return $this->root.'/'.ltrim($relative, '/');
    }

    private function exists(string $relative): bool
    {
        return is_file($this->absolute($relative));
    }

    /** A null pointer holds here, because the parser has already refused it wherever the class requires one. */
    private function pointerHolds(?string $pointer): bool
    {
        return $pointer === null || RegistryDocsManifest::isUrl($pointer) || $this->exists($pointer);
    }
}
