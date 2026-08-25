<?php

namespace Rushing\Popcorn\Registries;

/**
 * What sits at an address in a registry's keyspace: an entry, a derived branch, or nothing.
 *
 * The three cases are exactly the three branches {@see Registry::resolve()} already takes, named so a
 * caller can ask WITHOUT taking them (registry-kernel ticket 46). Branch nodes are synthesized from
 * the leaves — `a.b.c` makes `a.b` a branch whether or not anything is registered there — so a lazy
 * tree that probes a node before expanding it hits `AmbiguousRegistryMatch` on every branch a user
 * clicks, and the only non-throwing probe was `has()`, whose `false` cannot tell "a branch lives here"
 * from "no such key".
 *
 * Three consumers arrived at this same gap independently before it was filled: ticket 23 flagged
 * throw-on-ambiguity as the one place two closed tickets did not obviously agree; ticket 30 found
 * json-ns's dispatcher already probing with `has()` because a version pin is structurally a CHILD of
 * its stem; ticket 32 found that the ergonomic call — `descendants()` — hands a membership question
 * the wrong answer, because it mints a {@see BranchKey} for every intermediate address.
 *
 * ## It reports what resolve() will do, not whether the node has children
 *
 * An address may hold an entry AND have entries below it. This reports {@see Entry} there, because
 * that is what `resolve()` answers; ask {@see Nested::children()} — which never throws — whether it
 * is also expandable. Making that a fourth case would put the tree's expandability question inside
 * the resolution question, and a caller would have to destructure both to use either.
 *
 * ## Filtering makes a node Absent, and that is the disclosure decision
 *
 * Every case is measured on VISIBLE entries, so an address whose only entries an {@see Authorizer}
 * hides reads `Absent`, not `Branch`. That is ticket 29 D6's finding arriving at a single node:
 * filtering a tree removes structure, not just leaves, so two actors see differently-shaped trees.
 * Reporting `Branch` for a hidden subtree would leak the existence of every gated branch to every
 * caller — the one thing a filtered read exists to prevent. A caller that must see the whole shape
 * asks the registry {@see Registry::unfiltered()} first, and takes the disclosure decision itself.
 */
enum RegistryNode
{
    /** At least one visible entry sits exactly at this address; `has()` is true. */
    case Entry;

    /**
     * No entry here, but visible entries live below — the address is derived from its leaves.
     *
     * `resolve()` throws `AmbiguousRegistryMatch` on this case and will go on doing so: a caller that
     * asked for one thing and named a branch asked a question with several answers. This is the way to
     * not ask it, not a softening of the answer.
     */
    case Branch;

    /** Nothing visible at or below the address. */
    case Absent;
}
