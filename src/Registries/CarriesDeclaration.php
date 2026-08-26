<?php

namespace Rushing\Popcorn\Registries;

/**
 * A registry that carries its {@see IsRegistry} declaration **per instance** rather than on its class.
 *
 * {@see RegistryIndex::describe()} has to ask an incoming store what root it owns. Its default answer
 * is the class attribute, read by `IsRegistry::of()` — the only mechanism a static walk can see, which
 * is what lets the surgeon gate check conformance without booting (ticket 01 §4, ticket 14). This
 * interface is the other half: a store whose declaration is a *value* says so, and the index asks it
 * instead of guessing.
 *
 * ## Why this exists, and why it is not a second way to declare
 *
 * It is not a second declaration. It is the same {@see IsRegistry} — the one attribute type — reached
 * through a door instead of through a type test. Nothing new can be said here that the attribute could
 * not say, which is the property that keeps it off ticket 07 D4 / 01 D10's drift-surface list.
 *
 * ## The population that forced it (registry-kernel ticket 59, B1)
 *
 * `declarationOf()` used to type-test {@see BasicRegistry}, defended on the grounds that the test was
 * on the kernel's own reference implementation rather than on a consumer's type. Archetype **f** —
 * external store: a directory, a database table — is the population that defence did not anticipate.
 * An f registry holds no `BasicRegistry`, because holding no array is its *defining property*, so it
 * fell through to the class attribute. And the class attribute is exactly what ticket 26 D2 forbids
 * for that family: `FilesystemSchemaRegistry` is simultaneously the default `SchemaRegistry` binding,
 * **both** rungs of `ServedSchemaChain`, and the `file` tier inside `BeamSchemaRegistry`. One
 * class-level attribute asserts one root across four roles and lies about the rungs.
 *
 * So the sweep brief's §3a-f step 5 — *"declare INLINE, not on the class"* — was unimplementable as
 * written. This is what makes it implementable.
 *
 * ## Precedence, stated once
 *
 * An instance declaration WINS over a class attribute where both exist. The instance is the more
 * specific statement and it is the one a composite's rungs need; a class attribute that disagrees with
 * it is the lie step 5 exists to prevent, not a tiebreak to honour.
 *
 * Optional, and off {@see Registry} for the same reason {@see Filled}, {@see Gated}, {@see Nested} and
 * {@see Forgettable} are: a registry that declares on its class should not have to answer this, and the
 * type system should carry the fact rather than an empty method body (ticket 07 D8, 08 D8).
 */
interface CarriesDeclaration
{
    /**
     * What this instance declares it is — its root, what it is `of`, its arity, its duplicate policy
     * and its optionality.
     *
     * Total, never null. A store that cannot answer should not implement this: an undeclared registry
     * is precisely what the gate exists to catch, and a null here would launder it into a default.
     */
    public function declaration(): IsRegistry;
}
