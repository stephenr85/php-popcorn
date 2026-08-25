<?php

namespace Rushing\Popcorn\Concerns;

use Attribute;

/**
 * Marks a method as one link in a NAMED CHAIN — the explicit half of {@see ChainsTraitMethods}.
 *
 * ## Why an attribute and not only a naming convention
 *
 * Eloquent's `Model::bootTraits()` is the estate's precedent for this whole idea, and it shipped as a
 * naming convention first: a trait `HasUuids` contributes by declaring `bootHasUuids()`. Laravel has
 * since grown `#[Boot]` / `#[Initialize]` alongside that convention, and the attribute form is the one
 * worth copying, for three reasons the convention cannot answer:
 *
 *  - **A trait may contribute more than one link.** `bootHasUuids()` is one method per trait per chain,
 *    because the name IS the identity. An attribute lets a trait declare two steps in one chain, or one
 *    step in each of two chains, without inventing suffixes.
 *  - **Renaming the trait silently unhooks the method.** Under the convention, `HasUuids` → `HasUlids`
 *    turns `bootHasUuids()` into an ordinary uncalled method, with nothing failing anywhere. This map
 *    has already found that shape twice (a dead `afterResolving` hook, a lying `registerHint`), and both
 *    times the cost was months of a mechanism being quietly off.
 *  - **The chain name is stated, not derived.** `#[Chained('boot')]` says what it joins; `bootFoo()`
 *    requires knowing that `boot` is a prefix and `Foo` is a trait basename.
 *
 * The convention is still honoured ({@see TraitMethods::in()}) — a `{chain}{TraitBasename}` method joins
 * without an attribute, so an existing Eloquent-shaped trait works unchanged. The attribute is what a NEW
 * link should use.
 *
 * @see ChainsTraitMethods for the caller-facing half
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Chained
{
    /**
     * @param  string  $chain  the chain this method joins — the same token passed to
     *                         {@see ChainsTraitMethods::chainTraitMethods()}. A bare name (`'boot'`,
     *                         `'filters'`), never a method name.
     * @param  int  $order  ⚠️ **Ordering is DECLARED, never positional** — lower runs first, default 100,
     *                      mirroring `IsRegistry`'s own `order:` so one estate has one answer to "in what
     *                      order do the registered things run".
     *
     * Positional order was the obvious design and it is unusable here: `vendor/bin/pint` ships the Laravel
     * preset's `ordered_traits` fixer, which sorts a class's `use` statements ALPHABETICALLY. It rewrote
     * this mechanism's own test fixtures the first time it ran over them. A chain whose correctness
     * depended on `use` order would be silently re-sequenced by the formatter on an unrelated commit —
     * with nothing failing, which is the exact shape this estate has been bitten by repeatedly.
     *
     * Ties keep discovery order (traits first, then the composing class's own links), so a chain whose
     * steps are genuinely independent declares nothing and still runs sensibly.
     */
    public function __construct(
        public string $chain,
        public int $order = 100,
    ) {}
}
