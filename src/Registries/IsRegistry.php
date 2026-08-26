<?php

namespace Rushing\Popcorn\Registries;

use Attribute;
use InvalidArgumentException;
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
 * ## `arity` is a LIST, and `entryType` is not
 *
 * `arity` is the read path outermost-first, because two shipped registries read in two steps and their
 * descriptors recorded only the inner one: `PipelineRegistry` picks a named pipeline and then composes
 * that pipeline's stages, and `ResourceRenderingRegistry` picks a resource and then runs that resource's
 * renderings. Two beneficiaries is ticket 08 D1's bar for widening a kernel field, and the single-member
 * list — 77 of 79 registries — must not become ceremony, so a bare case is accepted and normalised
 * (registry-kernel ticket 47).
 *
 * `entryType` was chartered to widen in the same edit (ticket 34 D7) and **did not**, because the same
 * bar refuses it: a live census of all 39 declarations that name one found **zero** holding entries of
 * two types. Five say `'mixed'`, and each is a single-typed registry under-declaring itself for its own
 * reason — a lazily-held class-string, a tuple with no FQCN, four collections of which one is
 * addressable. The one candidate, `ParticleResourceRegistry`'s *"a ParticleResource OR a raw
 * ResourceDefinition"*, is a **stale note**: its own `@var` says `array<string, ParticleResource>` and
 * the `instanceof` filter it describes was removed. A list here would have been this map's fourth
 * memberless enumeration. The fix that was actually wanted — the index's reader saying what a registry
 * holds instead of shrugging — is had by declaring the type, which the scalar already expresses.
 *
 * ## What is deliberately not a field
 *
 * - **No `seam`.** `ManifestSeam` is deleted (ticket 07 D1) — see {@see RegistryArity}.
 * - **No `where`.** Superseded by `Registrar::source()`, which is DERIVED from the registrars a
 *   registry actually has rather than hand-written beside them, and so cannot drift (ticket 07 D4).
 * - **No `registerHint`.** With `of` + `entryType` + {@see HasRegistryKey} + the resolve/tryResolve
 *   pair, most of the estate's 52 hand-written hints are derivable; the residue is caveats, which is
 *   what `note` carries. That deletes 52 drift surfaces (ticket 01 D10).
 * - **No `tags`.** Grouping is **membership in a registry**, not a field on the declaration: if you want
 *   "every registry on the operator surface", register them into a registry keyed by {@see RegistryKey}
 *   and read its `keys()`. That is strictly more capable than a flat tag list — it is gated per entry by
 *   the same {@see Authorizer} (a static tag cannot answer *who is asking*, which is the one thing that
 *   docblock says a tag cannot express), it carries a payload rather than a bare string, and it is this
 *   package's own doctrine applied to itself. Ticket 05 §5 decided the opposite and ticket 06 D12
 *   relocated the read to a `Tagged` companion; **both are overruled by ticket 31**, on ticket 17 D3.
 *   Note this is a POINTER, not a bare refusal: the grouping mechanism exists and is specified — it is
 *   built beam-side by the successor UX map, not here. There is no `Tagged` interface and no
 *   `matching()`; do not re-add one without reading ticket 31.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class IsRegistry
{
    /**
     * The read path, outermost first — how many entries each step of a read engages OUT.
     *
     * Always a list, never a bare case, so that every reader and every wire projection sees one shape.
     * A sometimes-scalar field is the worst thing to put on a wire, and `popcorn:registries --json` is
     * the presumptive TS wire shape (ticket 16).
     *
     * Depth is DESCRIPTIVE of the read path a registry actually ships — it is not an open-ended nesting
     * vocabulary. Nothing in the estate reads in three steps; if something does, it says so here, and
     * that is the whole mechanism. A registry that wants addressable inner entries wants a nested
     * registry with its own root, not a third member.
     *
     * @var non-empty-list<RegistryArity>
     */
    public array $arity;

    /**
     * @param  string  $root  the branch of the keyspace this registry owns, e.g. `beam.particle.resources`
     * @param  string  $of  what it is a registry OF, in plain words — prose, for the index's own reader
     * @param  RegistryArity|array<array-key, mixed>  $arity  the read path, outermost first — a list of
     *                                                        {@see RegistryArity}, or a bare case, which
     *                                                        is the normal case (77 of 79) and is
     *                                                        normalised to a one-member list. Typed
     *                                                        loosely on purpose: an attribute's arguments
     *                                                        are hand-written and reflection instantiates
     *                                                        whatever was written, so the members are
     *                                                        checked below at runtime rather than
     *                                                        assumed. A malformed declaration then fails
     *                                                        to instantiate, and the conformance audit
     *                                                        reports it as an absent argument instead of
     *                                                        fatalling on it (`instantiate()` catches)
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
        RegistryArity|array $arity,
        public string $entryType = 'mixed',
        public OnDuplicate $onDuplicate = OnDuplicate::Supersede,
        public Optionality $optionality = Optionality::Optional,
        public ?string $note = null,
        public int $order = 100,
    ) {
        $arity = $arity instanceof RegistryArity ? [$arity] : array_values($arity);

        if ($arity === []) {
            throw new InvalidArgumentException(sprintf(
                'Registry [%s] declares an empty arity. A read engages at least one entry; declare the '
                .'outermost step, or a list of steps outermost-first.',
                $root,
            ));
        }

        foreach ($arity as $step) {
            if (! $step instanceof RegistryArity) {
                throw new InvalidArgumentException(sprintf(
                    'Registry [%s] declares an arity step that is not a %s.',
                    $root,
                    RegistryArity::class,
                ));
            }
        }

        $this->arity = $arity;
    }

    /**
     * Read the declaration governing a class — its own, or the nearest one above it — or null where
     * neither it nor any ancestor makes one.
     *
     * Reflection here, at runtime, is the convenience half; the static half is the surgeon gate reading
     * the same attribute off the AST without booting. Both see one source of truth, which is the entire
     * reason the declaration is an attribute.
     *
     * ## Why this walks up, when PHP does not
     *
     * PHP does not inherit class attributes, so `getAttributes()` on a subclass of a declared registry
     * returns nothing. That is not an edge case in this estate: **subclassing is the shipped extension
     * mechanism** — beam-core's `registerDefaults()` is an overridable hook, and swapping in an anonymous
     * subclass to prove a facade is fakeable is a live test idiom. Without the walk, such a subclass is
     * not merely invisible to the conformance audits (the mild half); it **cannot be constructed at all**,
     * because {@see BasicRegistry::for()} reads `static::class` and throws on a missing declaration.
     * Registry-kernel ticket 41 D11 decided the walk and ticket 42 landed it, after ticket 28 found the
     * fatal half in `laravel-graphine`'s own suite.
     *
     * **Nearest wins**, so a subclass that wants its own branch of the keyspace still takes it by
     * declaring — `Rushing\DataNav\NavInvocableRegistry` is the estate's exemplar. Inheriting and
     * overriding are therefore both expressible, and the default is the one that cannot break a boot.
     *
     * The walk is the CLASS parent chain only. An `#[IsRegistry]` on an interface governs the interface,
     * never its implementers: a root is a branch of the keyspace with one owner, and letting a contract
     * hand the same root to every implementer would manufacture the root collision this attribute exists
     * to make detectable.
     *
     * @param  class-string|object  $class
     */
    public static function of(object|string $class): ?self
    {
        $declaringClass = self::declaredOn($class);

        if ($declaringClass === null) {
            return null;
        }

        return (new ReflectionClass($declaringClass))->getAttributes(self::class)[0]->newInstance();
    }

    /**
     * The class the governing declaration is physically written on — the given class itself where it
     * declares, otherwise the nearest ancestor that does, otherwise null.
     *
     * Separate from {@see of()} because a reader that must distinguish *declared here* from *inherited
     * from there* cannot recover the difference from the value: two classes sharing one declaration share
     * one root, and whether that is one registry with two seeding sites or two registries colliding is
     * answered by WHERE the attribute sits, not by what it says. The conformance audit's collision check
     * is that reader.
     *
     * @param  class-string|object  $class
     * @return class-string|null
     */
    public static function declaredOn(object|string $class): ?string
    {
        $reflection = new ReflectionClass($class);

        while ($reflection !== false) {
            if ($reflection->getAttributes(self::class) !== []) {
                return $reflection->getName();
            }

            $reflection = $reflection->getParentClass();
        }

        return null;
    }

    /**
     * The declared root as a parsed key. Throws {@see Exceptions\InvalidRegistryKey} if it is not one.
     *
     * The empty string is the one legal non-key here, and it means the ROOT of the whole tree — see
     * {@see Key::root()}. Only {@see RegistryIndex} declares it, because only the index owns the whole
     * keyspace rather than a branch of it. It is spelled out here rather than in `Key::parse()` so
     * that an empty string arriving from anywhere ELSE still throws (ticket 20).
     */
    public function rootKey(): RegistryKey
    {
        return $this->root === '' ? Key::root() : Key::parse($this->root);
    }
}
