<?php

namespace Rushing\Popcorn\Registries;

use Attribute;
use ReflectionClass;

/**
 * A registry declaring itself: the branch of the keyspace it owns, what it is a registry OF, how a read
 * engages it, and what it does with a duplicate.
 *
 * ## Why an attribute rather than an interface method
 *
 * Because it is the only mechanism a `php-parser` walk can read **without booting the application** —
 * which is what lets the surgeon gate check conformance statically, and what makes two packages
 * declaring the same `root` a detectable collision rather than a silent last-wins at boot
 * (registry-kernel ticket 01 §4, ticket 14, ticket 20).
 *
 * Named `IsRegistry` rather than `Registry` because `#[Registry]` would stutter against the
 * {@see Registry} interface at every use site.
 *
 * ## `root`, not `namespace`
 *
 * This is the one package where "which namespace do you mean" must never be ambiguous — the attribute
 * sits a few lines below a PHP `namespace` declaration. The domain term in prose is *the registry's
 * root key*. It is a {@see Key}-legal dotted key, **domain-first and vendor-free**: `beam.realm.overlays`,
 * `popcorn.registries`, `graph.stores` — never composer-coordinate-derived, because a coordinate root
 * fragments the tree by who ships a thing rather than what it is, and would turn any future repackaging
 * into a rekey of persisted keys (ticket 05).
 *
 * Roots may NEST — `beam.realm` and `beam.realm.overlays` may both be declared, and the index routes by
 * longest prefix. The constraint is only that no two registries declare the SAME root.
 *
 * ## What is deliberately not a field
 *
 * - **No `seam`.** `ManifestSeam` is deleted (ticket 07 D1) — see {@see RegistryArity}.
 * - **No `where`.** Superseded by `Registrar::source()`, which is DERIVED from the registrars a
 *   registry actually has rather than hand-written beside them, and so cannot drift (ticket 07 D4).
 * - **No `registerHint`.** With `of` + `entryType` + {@see HasRegistryKey} + the resolve/tryResolve
 *   pair, most of the estate's 52 hand-written hints are derivable; the residue is caveats, which is
 *   what `note` carries. That deletes 52 drift surfaces (ticket 01 D10).
 */
#[Attribute(Attribute::TARGET_CLASS)]
class IsRegistry
{
    /**
     * @param  string  $root  the branch of the keyspace this registry owns, e.g. `beam.particle.resources`
     * @param  string  $of  what it is a registry OF, in plain words — prose, for the index's own reader
     * @param  RegistryArity  $arity  how many entries a read engages OUT
     * @param  string  $entryType  the FQCN every entry is, or `'mixed'` — but it must SAY so. One entry
     *                             type per registry; where a class serves two output shapes that is a
     *                             port's job, not the kernel's (ticket 01 D3)
     * @param  OnDuplicate  $onDuplicate  what `register()` does when the key is taken. Declared, not
     *                                    fixed — the estate ships all three with argued docblocks, so a
     *                                    kernel that picked one would break the other two (ticket 06 D2)
     * @param  Optionality  $optionality  whether EMPTY is an error. OSGi's axis, split from arity, and
     *                                    enforced at read because emptiness is runtime state (06 D5/D6)
     * @param  string|null  $note  the irreducible caveat, where one exists. A handful of the estate's
     *                             descriptors need one; most do not
     * @param  int  $order  render order, foundation first (ascending). Display only — it is NOT a
     *                      resolution rank; ordering of entries is registration order (ticket 08 D4)
     */
    public function __construct(
        public string $root,
        public string $of,
        public RegistryArity $arity,
        public string $entryType = 'mixed',
        public OnDuplicate $onDuplicate = OnDuplicate::Supersede,
        public Optionality $optionality = Optionality::Optional,
        public ?string $note = null,
        public int $order = 100,
    ) {}

    /**
     * Read a class's own declaration, or null where it makes none.
     *
     * Reflection here, at runtime, is the convenience half; the static half is the surgeon gate reading
     * the same attribute off the AST without booting. Both see one source of truth, which is the entire
     * reason the declaration is an attribute.
     */
    public static function of(object|string $class): ?self
    {
        $attributes = (new ReflectionClass($class))->getAttributes(self::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /** The declared root as a parsed key. Throws {@see Exceptions\InvalidRegistryKey} if it is not one. */
    public function rootKey(): RegistryKey
    {
        return Key::parse($this->root);
    }
}
