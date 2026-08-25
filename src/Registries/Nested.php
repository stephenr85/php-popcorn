<?php

namespace Rushing\Popcorn\Registries;

/**
 * A registry that reads its keyspace as a TREE — enumeration, routing and display.
 *
 * Optional, and separate from {@see Registry} on purpose (registry-kernel ticket 05). A dotted key is a
 * real tree for those three jobs and **flat for resolution**: `resolve()` never walks up a key, and
 * `beam.realm.overlays.article` does not fall back to `beam.realm.overlays`. Where an entry genuinely
 * inherits, the registry DECLARES the chain — it is never inferred from how someone spelled a key.
 *
 * Both walks are segment-wise, never character-wise: `beam.realms` is not a child of `beam.realm`.
 */
interface Nested
{
    /**
     * The keys exactly one segment below `$key`, in registration order.
     *
     * A branch is not required to hold an entry of its own — `beam.realm.overlays.article` makes
     * `beam.realm.overlays` a child of `beam.realm` whether or not anything is registered there.
     *
     * @return list<RegistryKey>
     */
    public function children(RegistryKey|string $key): array;

    /**
     * Every key strictly below `$key` at any depth, in registration order.
     *
     * ⚠️ **This includes derived branch addresses, so it is the wrong call for a MEMBERSHIP question**
     * (registry-kernel ticket 32). `a.b.c` puts a {@see BranchKey} for `a.b` in this list, and `has()`
     * denies it while `resolve()` throws on it. A rule, a picker or an autocomplete asking "is this a
     * legal key" must filter with {@see nodeAt()} — or with `has()` — rather than trust the walk.
     *
     * @return list<RegistryKey>
     */
    public function descendants(RegistryKey|string $key): array;

    /**
     * What sits at `$key`: an entry, a derived branch, or nothing — without throwing on any of them.
     *
     * The non-throwing probe (registry-kernel ticket 46). `resolve()` reports a branch as ambiguous
     * because a caller that named one asked a question with several answers; that stays. This is the
     * way to ask a different question, and it is the one call that separates the three states a lazy
     * tree, a validation rule and a dispatcher each had to reconstruct for themselves.
     *
     * Deliberately three-state rather than an `isBranch(): bool`. The narrow spelling would leave a
     * caller that wants entry/branch/absent making two calls — and every consumer this ticket was
     * filed from wants all three — while shipping both would be two ways to say one thing, which is
     * the drift surface this map has refused since ticket 01 D10.
     *
     * See {@see RegistryNode} for what each case means, why an entry that also has children reports
     * `Entry`, and why a filtered-away subtree reports `Absent`.
     */
    public function nodeAt(RegistryKey|string $key): RegistryNode;
}
