<?php

namespace Rushing\Popcorn\Registries;

/**
 * A registry that can be filled by {@see Registrar}s rather than only by hand.
 *
 * Optional, and off {@see Registry} for the same reason {@see Nested}, {@see Forgettable} and
 * {@see Gated} are: a registry that only ever hand-registers should not have to answer `attach()`, and
 * the type system should carry the fact rather than an empty method body (registry-kernel ticket 07 D8).
 *
 * ## Registrars are wiring, not identity — so they are never declared on the attribute
 *
 * PHP 8.1's new-in-initializers would permit `#[IsRegistry(registrars: [new ConfigRegistrar('…')])]`,
 * and that is precisely wrong. {@see IsRegistry} carries what a registry IS — its root, what it is of,
 * its arity. Registrars carry where a PARTICULAR HOST's entries come from. Freezing a config key into an
 * attribute breaks the moment a host rebinds it, and the scan paths a registrar needs already come from
 * config in three live cases (`beam.core.resources.discover_paths`, `beam.core.realms.classes`, and
 * beam-mcp's). So attachment is imperative, at the owner's `boot()`.
 *
 * ## `registrars()` is read generically, by the index
 *
 * It is not introspection for its own sake: {@see RegistryIndex}'s "how do I contribute to this?" column
 * is DERIVED by enumerating a registry's registrars and rendering each one's
 * {@see Registrar::source()}. That is what retired the hand-written `where` field, and it only works if
 * the list is readable without knowing the registry's concrete type (ticket 07 D4).
 */
interface Filled
{
    /**
     * Attach a registrar and let it fill this registry NOW.
     *
     * Eager, and the eagerness is the decision (ticket 07 D9). An owner attaches in its own `boot()`,
     * which Laravel runs before the consumer providers that hand-register — so an explicit registration
     * lands after a registrar's and wins by {@see OnDuplicate::Supersede}, with no tier and no
     * precedence rule to maintain. That reproduces the estate's existing explicit-wins semantics by
     * ordering alone.
     *
     * The rejected alternative was store-now-fill-lazily, which inverts it exactly: registrars would run
     * after everything else and config would beat explicit registration — a silent behaviour change in
     * every registry that has one.
     */
    public function attach(Registrar $registrar): void;

    /**
     * Every attached registrar, in attachment order.
     *
     * @return list<Registrar>
     */
    public function registrars(): array;
}
