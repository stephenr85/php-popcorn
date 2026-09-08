<?php

namespace Rushing\Popcorn\Registries;

use Attribute;
use ReflectionClass;

/**
 * Declares a registry's root key, entry type, policies, and optional description.
 *
 * The attribute can be read statically without booting the application, so tooling can inspect
 * declarations and detect duplicate roots. At runtime, {@see of()} reads the same declaration.
 *
 * A root is a domain-first, vendor-free dotted key, such as `beam.realm.overlays` or `graph.stores`.
 * Roots may nest; the index routes by longest prefix. Only the index owns the empty root.
 *
 * `entryType` identifies what entries hold. `description` explains the registry's purpose and use
 * where that helps a consumer. Read behavior belongs to the registry and its consumers.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class IsRegistry
{
    /**
     * @param  string  $root  the branch of the keyspace this registry owns
     * @param  string  $entryType  the entry type, or `'mixed'`
     * @param  OnKeyDuplicate  $onKeyDuplicate  what `register()` does when the key is taken
     * @param  PopulationRequirement  $populationRequirement  whether `resolve()` reports an empty registry as unpopulated
     * @param  string|null  $description  optional context about the registry's purpose and use
     * @param  int  $order  display order, ascending; entry order is registration order
     */
    public function __construct(
        public string $root,
        public string $entryType = 'mixed',
        public OnKeyDuplicate $onKeyDuplicate = OnKeyDuplicate::Supersede,
        public PopulationRequirement $populationRequirement = PopulationRequirement::Optional,
        public ?string $description = null,
        public int $order = 100,
    ) {}

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
     * Typed to the concrete {@see Key} rather than {@see RegistryKey}: a root is always written as a
     * dotted string in an attribute, so it has only ever been a `Key`, and {@see Rootable::underRoot()}
     * needs to be able to say so (registry-kernel ticket 64).
     *
     * The empty string is the one legal non-key here, and it means the ROOT of the whole tree — see
     * {@see Key::root()}. Only {@see RegistryIndex} declares it, because only the index owns the whole
     * keyspace rather than a branch of it. It is spelled out here rather than in `Key::parse()` so
     * that an empty string arriving from anywhere ELSE still throws (ticket 20).
     */
    public function rootKey(): Key
    {
        return $this->root === '' ? Key::root() : Key::parse($this->root);
    }
}
