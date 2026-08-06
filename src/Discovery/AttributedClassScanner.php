<?php

namespace Rushing\Popcorn\Discovery;

use ReflectionClass;
use ReflectionException;
use Symfony\Component\Finder\Finder;

/**
 * A generic, resource/realm-agnostic file→class→attribute scanner.
 *
 * Enumerates the `*.php` files under the given paths, derives each file's FQCN from its
 * `namespace` + `class`/`enum`/`interface`/`trait` declaration, loads it, and keeps only the
 * class-strings that CARRY the requested attribute. It knows nothing about resources, realms,
 * or any consumer's vocabulary — callers pass the attribute class they care about
 * (`#[ParticleResource]`, `#[Realm]`, …) and get back the matching `class-string[]`.
 *
 * Extracted from `Splicewire\Beam\Frame\AdminResourceRegistry::scanPaths()` /
 * `classNameFromFile()` (they were duplicated verbatim in beam's `RealmDiscovery`); this is the
 * single home for the machinery so every discoverer consumes one implementation.
 *
 * Behaviour preserved from the beam original:
 *  - non-existent paths are silently skipped (filtered, never an error);
 *  - files whose derived class does not exist / is not loadable are skipped;
 *  - only attribute-carrying classes are returned — an un-attributed class under a scanned path is
 *    ignored, so a path may point at a whole Data directory.
 */
class AttributedClassScanner
{
    /**
     * Scan the given filesystem paths and return the class-strings carrying $attributeClass.
     *
     * @param  array<int, string>  $paths  filesystem paths (files or directories) to scan
     * @param  class-string  $attributeClass  the attribute a class must carry to be kept
     * @param  bool  $instanceof  when true (default), preset SUBCLASSES of the attribute
     *                            match too (`ReflectionAttribute::IS_INSTANCEOF`); when
     *                            false, only the exact attribute matches
     * @return list<class-string>
     */
    public function scan(array $paths, string $attributeClass, bool $instanceof = true): array
    {
        $existing = array_filter($paths, 'file_exists');

        if ($existing === []) {
            return [];
        }

        $found = [];

        $finder = (new Finder)->files()->name('*.php')->in($existing);

        foreach ($finder as $file) {
            $class = $this->classNameFromFile($file->getRealPath());

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if ($this->hasAttribute($class, $attributeClass, $instanceof)) {
                $found[] = $class;
            }
        }

        return $found;
    }

    /**
     * Whether $class carries $attributeClass (optionally matching preset subclasses).
     *
     * @param  class-string  $class
     * @param  class-string  $attributeClass
     */
    public function hasAttribute(string $class, string $attributeClass, bool $instanceof = true): bool
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return false;
        }

        $flags = $instanceof ? \ReflectionAttribute::IS_INSTANCEOF : 0;

        return $reflection->getAttributes($attributeClass, $flags) !== [];
    }

    /**
     * Derive a class-string from a PHP file's `namespace` + type declaration. Matches
     * class/enum/interface/trait so a scanned directory of mixed declarations resolves.
     *
     * Tokenizes rather than regex-matches: a naive `class\s+(\w+)` regex false-matches prose like
     * "a first-class Capability" inside a docblock, or a `Foo::class` reference, as if it were the
     * declaration itself. PHP's own lexer can't be fooled by either — it only ever emits `T_CLASS`
     * for a real class keyword, comments are their own token type, and a `::class` fetch is
     * distinguished by the preceding `::`.
     *
     * @return class-string|null
     */
    public function classNameFromFile(string $path): ?string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $tokens = token_get_all($contents);
        $namespace = '';

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id] = $token;

            if ($id === T_NAMESPACE) {
                $namespace = self::readName($tokens, $i + 1);

                continue;
            }

            if (in_array($id, [T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT], true) && ! self::precededByDoubleColon($tokens, $i)) {
                $name = self::nextIdentifier($tokens, $i + 1);

                if ($name === null) {
                    // `new class { ... }` — an anonymous class carries no declared name to derive.
                    continue;
                }

                /** @var class-string */
                return $namespace === '' ? $name : $namespace.'\\'.$name;
            }
        }

        return null;
    }

    /** Whether the token at $index is a `::class` fetch rather than a real type keyword. */
    private static function precededByDoubleColon(array $tokens, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token === '::';
        }

        return false;
    }

    /** The next real identifier after $index, skipping whitespace/comments — or null (e.g. anonymous class). */
    private static function nextIdentifier(array $tokens, int $index): ?string
    {
        for ($j = $index; $j < count($tokens); $j++) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    /** Read a (possibly qualified) name starting at $index, up to the terminating `;` or `{`. */
    private static function readName(array $tokens, int $index): string
    {
        $name = '';

        for ($j = $index; $j < count($tokens); $j++) {
            $token = $tokens[$j];

            if ($token === ';' || $token === '{') {
                break;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];
            }
        }

        return $name;
    }
}
