<?php

namespace Rushing\Popcorn\Registries;

/**
 * A registry that can be handed the host's {@see Authorizer}.
 *
 * Optional, and separate from {@see Registry} for the same reason {@see Forgettable} and {@see Nested}
 * are: a capability the type system carries, so a registry that cannot receive an authorizer says so
 * rather than silently ignoring one (registry-kernel ticket 20).
 *
 * ## The subject is the registry, not the actor
 *
 * Laravel's `Illuminate\Contracts\Auth\Access\Authorizable` is the word for the OTHER side of this —
 * `can($abilities, $arguments)`, implemented by the User model, the entity that HOLDS abilities. This
 * is the entity that is GATED BY them. Adopting Laravel's name would type registries as actors, and
 * `package-topology.mustNotRequire: ["illuminate/*"]` bars the reference from this package regardless.
 * The Laravel-side convergence happens where it is allowed to: `rushing/laravel-popcorn` ships an
 * {@see Authorizer} that bridges to `Gate`.
 *
 * ## One authorizer, installed once, pushed down
 *
 * There is exactly one authorizer and {@see RegistryIndex} holds it (ticket 09 D7) — per-registry
 * wiring means a registry that forgets to wire it is silently open. The index pushes it into every
 * registry it holds, and because those are the same container singletons a consumer injects directly,
 * a registry reached WITHOUT going through the index is filtered too.
 *
 * The push happens on both edges deliberately: `RegistryIndex::authorizeWith()` stamps the registries
 * it already holds, and every later `describe()` stamps the incoming one. Only one edge would make
 * filtering depend on whether an owner package booted before or after the host installed its policy —
 * an ordering nobody controls, and silently open on the losing side.
 */
interface Gated
{
    /**
     * Install the host's authorizer, or `null` to remove it.
     *
     * Idempotent and last-wins: this is a wiring call made at boot by the index, not a per-request
     * scope. Registration is never gated and neither is this — see {@see Authorizer}.
     */
    public function authorizeWith(?Authorizer $authorizer): static;
}
