<?php

namespace Rushing\Popcorn\Contracts;

/**
 * A class whose traits may contribute steps to a NAMED CHAIN it runs.
 *
 * The interface exists so a FRAMEWORK HOOK can detect chainability without the class remembering to call
 * anything — the same reason `Filled`, `Forgettable`, `Gated` and `Nested` are interfaces rather than
 * conventions. A base service provider in `laravel-popcorn` can run `chainTraitMethods('boot')` for every
 * provider that declares this, and a provider that does not declare it is untouched.
 *
 * Implement with {@see \Rushing\Popcorn\Concerns\ChainsTraitMethods}; there is no second implementation
 * and the trait is not optional in practice — the interface is the detector, not an invitation to
 * hand-roll the reflection.
 */
interface ChainsTraitMethods
{
    /**
     * Run every method joining `$chain`, in `TraitMethods::in()`'s declared order.
     *
     * @param  string  $chain  a bare chain name (`'boot'`, `'filters'`), never a method name
     * @param  mixed  ...$arguments  forwarded verbatim to each link
     * @return array<string, mixed> each link's return value, keyed by its method name — so a caller can
     *                              tell WHICH trait contributed WHAT, which a flat list cannot
     */
    public function chainTraitMethods(string $chain, mixed ...$arguments): array;
}
