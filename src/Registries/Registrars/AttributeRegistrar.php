<?php

namespace Rushing\Popcorn\Registries\Registrars;

use InvalidArgumentException;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Popcorn\Registries\HasRegistryKey;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;

/**
 * Fills a registry from the classes under a set of paths that carry a given attribute.
 *
 * The scan itself is {@see AttributedClassScanner}, which **stays exactly where it is** — it already
 * ships, `splicewire/laravel-beam`'s surgeon audits already consume it directly, and this class wraps it
 * rather than absorbing it (registry-kernel ticket 07 D2).
 *
 * ## It projects, because a scanned class is not usually the entry
 *
 * The deciding live case is `ParticleResourceRegistry`: it scans for `#[ParticleResource]`, then
 * projects each class-string into a runtime `ParticleResource` declaration and registers THAT under
 * `$resource->key`. A registrar hardcoded to write class-strings could not fill it, so `$project` is a
 * parameter — default identity, so the simpler registry that genuinely wants class-strings spells
 * nothing.
 *
 * ## Where the key comes from, and why a miss THROWS
 *
 * Three sources, in order:
 *
 * 1. an explicit `$key` callable, `fn (string $class, mixed $entry) => …`;
 * 2. otherwise {@see HasRegistryKey} on the projected entry — the seam ticket 01 D2 built for exactly
 *    this, because three of the four exemplars already self-key (`$invocable->name()`, `$schema['$id']`,
 *    `$resource->key`);
 * 3. otherwise it throws.
 *
 * There is deliberately no fallback that derives a key from the class name. Kebab-casing a short name is
 * a GUESS, and the estate's own precedent refuses guesses at exactly this altitude:
 * {@see \Rushing\Popcorn\Registries\BasicRegistry::for()} throws rather than inventing a root for an
 * undeclared owner, on the grounds that a silently invented identity hides the thing the surgeon gate
 * exists to catch. An invented KEY is the same defect one level down — it would resolve, it would look
 * right, and it would be wrong the first time two packages scanned classes with the same short name.
 *
 * ## Uncached, on every boot, on purpose
 *
 * The scan is a `Finder` walk plus a `class_exists` over every `*.php` under the scan paths, with no
 * cache of any kind — Popcorn has no cache infrastructure at all. That is the accepted cost of eager
 * registrars (ticket 07 D9), and it is what {@see CachedRegistrar} exists to pay down. Only this
 * registrar is wrapped by default: a config array read is free, and the costs are not comparable.
 */
class AttributeRegistrar implements Registrar
{
    /**
     * Never null after construction — the nullable argument is a DEFAULT, not a state. Held as a
     * promoted nullable property it made every use site a null the type system could not discharge,
     * and PHPStan said so (`Cannot call method scan() on AttributedClassScanner|null`).
     */
    private AttributedClassScanner $scanner;

    /**
     * `$paths` are scanned for `$attribute`; non-existent paths are skipped silently. `$project` maps a
     * scanned class-string to the entry to register (identity by default). `$key` maps
     * `(class-string, entry)` to the key, and falls back to {@see HasRegistryKey} on the entry.
     * `$instanceof` controls whether subclasses of the attribute match too.
     *
     * @param  list<string>  $paths
     * @param  class-string  $attribute
     * @param  (callable(class-string): mixed)|null  $project
     * @param  (callable(class-string, mixed): (\Rushing\Popcorn\Registries\RegistryKey|string))|null  $key
     */
    public function __construct(
        private array $paths,
        private string $attribute,
        private $project = null,
        private $key = null,
        private bool $instanceof = true,
        ?AttributedClassScanner $scanner = null,
    ) {
        $this->scanner = $scanner ?? new AttributedClassScanner;
    }

    /**
     * @template TEntry
     *
     * @param  Registry<TEntry>  $registry
     */
    public function fill(Registry $registry): void
    {
        foreach ($this->scanner->scan($this->paths, $this->attribute, $this->instanceof) as $class) {
            $entry = $this->project === null ? $class : ($this->project)($class);

            // The scanned class's own FQCN, per ticket 07 D13 — the registrant is the thing that
            // brought the entry into being, which for a scan is the annotated class and not the path
            // it happened to be found under.
            $registry->register($this->keyFor($class, $entry), $entry, by: $class);
        }
    }

    public function source(): string
    {
        $position = strrpos($this->attribute, '\\');

        $short = $position === false ? $this->attribute : substr($this->attribute, $position + 1);

        return "#[{$short}] under ".($this->paths === [] ? '(no paths)' : implode(', ', $this->paths));
    }

    /** @param  class-string  $class */
    private function keyFor(string $class, mixed $entry): mixed
    {
        if ($this->key !== null) {
            return ($this->key)($class, $entry);
        }

        if ($entry instanceof HasRegistryKey) {
            return $entry->registryKey();
        }

        throw new InvalidArgumentException(sprintf(
            'AttributeRegistrar cannot say what key `%s` registers under: its entry does not implement '
                .'%s, and no key callable was given. Pass `key:` or implement the interface — a key '
                .'derived from the class name would be a guess.',
            $class,
            HasRegistryKey::class,
        ));
    }
}
