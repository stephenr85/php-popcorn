<?php

namespace Rushing\Popcorn\Concerns;

/**
 * Lets a class's TRAITS contribute steps to a named chain it runs, instead of the class hand-listing
 * every step it must remember to call.
 *
 * ## The shape this exists to retire
 *
 * A composed class accumulates concerns, and each concern needs a line in some `boot()` — so the class
 * grows a call block that is a hand-maintained index of its own parts:
 *
 * ```php
 * public function packageBooted(): void
 * {
 *     $this->bootAuthorization();
 *     $this->bootMiddleware();
 *     $this->bootRouteMacro();
 *     // … 12 more
 * }
 * ```
 *
 * Every line is a chance to forget one, and the block says nothing the methods do not already say. Under
 * the chain, a concern becomes a trait carrying `#[Chained('boot')]`, the block deletes, and adding a
 * concern is `use`-ing it.
 *
 * ## Eloquent already proved this, for models only
 *
 * `Model::bootTraits()` is exactly this mechanism — `class_uses_recursive()`, then invoke each trait's
 * `boot{Trait}` / `#[Boot]` method — and it has carried Laravel's model concerns for a decade. It was
 * never generalized past `Model`, so every OTHER composed class in the estate hand-lists its steps.
 * Measured across `rushing`/`schemastud`/`splicewire`: **74 hand-written `$this->boot*()` / `register*()`
 * calls across 10 service providers**, three of them severe (beam-accounts 19, beam-ux 17, beam 11).
 *
 * ## Not a hook, and not an event
 *
 * A link runs because the class ASKED for the chain, at the point it asked. Nothing here subscribes,
 * defers, or fires on its own — a chain with no `chainTraitMethods()` call runs never, and that is
 * intentional: the register-down direction this package defends everywhere else applies to control flow
 * too. A trait declares what it can contribute; the composing class decides when.
 *
 * @see Chained  the explicit declaration
 * @see TraitMethods  the reflection half, and the order contract
 */
trait ChainsTraitMethods
{
    /**
     * Run every link in `$chain`.
     *
     * A `protected` link is reachable without `setAccessible()` — PHP 8.1 made reflection access the
     * default and this package requires 8.3 — so a trait's contribution stays protected, as an
     * implementation detail of the trait, rather than being forced public to be callable.
     *
     * A static link is invoked statically, so one chain can carry both a boot step that needs no instance
     * and an instance step that does. That is the case `Model::bootTraits()` splits into two mechanisms
     * (`boot{Trait}` static, `initialize{Trait}` per-instance); here it is one chain and the method's own
     * `static` keyword decides, because the distinction was never about WHICH chain.
     *
     * @param  mixed  ...$arguments  forwarded verbatim to each link
     * @return array<string, mixed> keyed by method name
     */
    public function chainTraitMethods(string $chain, mixed ...$arguments): array
    {
        $results = [];

        foreach (TraitMethods::in($this, $chain) as $method) {
            $results[$method->getName()] = $method->isStatic()
                ? $method->invoke(null, ...$arguments)
                : $method->invoke($this, ...$arguments);
        }

        return $results;
    }

    /**
     * The chain's links flattened into one list — the COLLECT idiom, for a chain whose links each return
     * an array of contributions (filters, casts, nav nodes) rather than performing a side effect.
     *
     * Kept beside `chainTraitMethods()` rather than left to the caller because the merge is where the
     * mistake lives: `array_merge` on string keys means last-wins silently, which for a contribution
     * chain is the shadowing failure this estate has now found at four levels. String keys are preserved
     * and a collision is REPORTED by the return shape — the caller gets the merged map, and can ask
     * `chainTraitMethods()` for the per-link breakdown when two links disagree.
     *
     * @return array<array-key, mixed>
     */
    public function collectTraitMethods(string $chain, mixed ...$arguments): array
    {
        $collected = [];

        foreach ($this->chainTraitMethods($chain, ...$arguments) as $contribution) {
            if (! is_array($contribution)) {
                continue;
            }

            $collected = array_merge($collected, $contribution);
        }

        return $collected;
    }
}
