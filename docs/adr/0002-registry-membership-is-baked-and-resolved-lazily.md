# ADR-0002 — Registry membership is baked at build time and resolved lazily

Status: accepted
Date: 2026-08-31

Registry-kernel ticket 73, decisions D2 (dev) and D3 (agent) — tracker:
`~/Workspaces/splicewire-ecosystem/.scratch/rushing/laravel-popcorn/registry-kernel/tickets/73-conforming-is-gated-and-describing-is-not.md`.

## Context

A registry entered the index by **two** authoring acts: `#[IsRegistry]` on the class, and a
hand-written `RegistryIndex::describe(...)` in the owning provider's `boot()`. Forgetting the second
was invisible to every instrument in the estate — measured five times in beam alone (`d3b2fd3`,
`1a127aa`: five registries declared, conformed, and sat outside the index for three days while a
conformance command, two audits, `surgeon:audit` and a 1793-test suite all read green), and then six
more in `a699757`, whose rows the sweep ledger recorded as **verified** while they were unindexed at
14 of 14 hosts.

Deriving membership from the declaration at **boot** was costed and refused. Walking the container's
bindings and reflecting each abstract costs **90–111 ms and 216 newly-autoloaded classes on a 662 ms
boot** — ~14% of every request, forever, to answer a question that only changes on deploy. The cheap
variant (`class_exists($a, false)`, skipping anything not already loaded) costs 0.29 ms and finds
**78 of 83**, with *which* five it misses depending on what that request happened to resolve first —
a detector that reports a **pass** rather than a miss.

## Decision

**The membership list is baked at build time from a static source scan, and the index resolves each
entry lazily on first read.**

- The artifact is `bootstrap/cache/popcorn-registries.php`, `root => class-string`, written by
  `popcorn:registries:cache` and rebuilt from `post-autoload-dump` beside `package:discover`.
  **Generated, never committed** — `.beam/*.json` is a *ratchet*, a document that is supposed to lag
  the code, whereas a membership list is a *build product* that must match it exactly.
  `Splicewire\Beam\Frame\FrameResourceManifest` is the precedent: the same job, one attribute over.
- `RegistryIndex::describeLazily($root, $class)` holds a string. The object is built on first
  `routeTo()`, **through a resolver the framework adapter supplies** — in Laravel, the container.

### Laziness is a correctness mechanism, not a performance one

This is the part worth keeping. Phase A measured four tower registries whose **classes live in one
package and whose container bindings live in the host's providers**, three of them behind configuring
closures. An eager `describe($app->make(...))` from the owning package therefore **fabricates a fresh,
unconfigured singleton wherever nothing binds one** — the AGENTS.md testbench trap in production form,
failing as an *answer* rather than an error. Resolving at read time means the host's own binding is
what answers, whatever it is and whenever it was registered.

That also dissolves the two structural blockers phase A hit: a registry declared in a
framework-agnostic package with no provider (`codegen.generators`, unindexed at 13 of 14 hosts) and one
whose owning package ships no provider at all (`beam.sync.scaffold-pack.content-kinds`). Neither can be
reached by any hand-written `describe()`; both are ordinary baked entries.

The index's class docblock previously argued the opposite — that holding class-strings *"would invert
the direction: the index would become the thing that decides when a consumer's registry comes into
being"*. That is overruled in place. The owner still declares, the bake still reads the owner's own
attribute, and the index still never scans at run time; only the construction moment moved, and it
moved to where the host controls it.

### The authorizer is pushed at resolution

`describe()` ends in `push($store)`, which installs the `Gated` authorizer. Under laziness that must
happen when the entry is *resolved*, not when it is *baked* — an entry in the index that was never
pushed is an unauthorized registry, and lazy resolution is exactly where the push could be dropped
with nothing noticing. Pinned by a kernel test whose fixture records being pushed and which asserts
nothing was pushed before the first read.

### Absent is a third state, and it is loud

| artifact | state | behaviour |
|---|---|---|
| present, non-empty | baked | normal |
| present, **empty** | baked | quiet — this host genuinely declares nothing |
| **absent** | unbaked | every membership read raises `UnbakedRegistryIndex` |

Once the hand-written describes are gone there is no fallback, so a missing artifact would otherwise
mean every `routeTo()` returning null, `popcorn:registries` showing nothing, and the authorizer never
installed — with nothing anywhere saying so. **Present-but-empty and absent must never be the same
value**; that is `Rushing\Doctor\Finding::inconclusive()`'s distinction, one tier down.

Deliberately **no fallback to a live scan**, which is where this departs from `FrameResourceManifest`:
that is the boot tax refused above, and a boot-time scan can kill the process outright — a missing
**trait** is an uncatchable `E_COMPILE_ERROR`, measured killing three `~/Herd` hosts.

It raises at the **door, not at boot**: the command that writes the artifact is an artisan command and
artisan boots the application, so an index that refused to boot unbaked could never be baked. A real
bootstrap cycle, pinned by a test.

This does not contradict *"a check whose answer depends on the host must not throw"*. That rule governs
facts which legitimately differ per host; **whether the build step ran is not one of them** — it is
identical at every host that completed its install and is never a legitimate state in which to serve a
request. ADR-0001 went the other way, for the opposite reason.

Three readers, not one: the throw, `popcorn:registries` printing the unbaked state in words, and
`Splicewire\Beam\Surgeon\UnindexedRegistryAudit` reporting `Fail` **and** inconclusive.

## Consequences

- The kernel's **default** is neither baked nor unbaked but *"membership supplied by hand"*, so every
  package testbench, every existing test and every non-Laravel consumer is untouched. Only a framework
  adapter that went looking for an artifact can call `markUnbaked()`. A hand `describe()` also clears
  blindness, because *unbaked* means **no membership source at all**.
- **The baked class-string must be the container key the host binds**, and nothing else checked that.
  A `class_alias` is one symbol under two container keys; a concrete bound under an interface puts the
  singleton under a name the declaration does not carry. Both were live (`CorpusStreamRegistry` at the
  flagship, `NodeSchema` in blockdoc — 2 of 84) and both are repaired; `popcorn:registries:cache` now
  reports a baked class bound under a different name, and reports only a *divergence*, never a mere
  absence.
- Hydration is per-root on the hot path and all-at-once for enumeration. Boot never enumerates, so the
  cost lands on tooling that was always going to touch everything.
- A baked class that does not implement `Registry` throws at hydration naming the conformance audit's
  `contract` check, which already gates it.
- ⚠️ **The full cutover of the hand-written `describe()` call sites is NOT part of this ADR.** It was
  prototyped (60 call sites across 38 files) and reverted: with the describes gone, a package testbench
  has neither an artifact nor a describe and is therefore blind, which reddened 11 package suites. How a
  testbench obtains membership is an open decision, and the prototype is kept as
  `registry-kernel/assets/73-phase-c-cutover.patch`.
