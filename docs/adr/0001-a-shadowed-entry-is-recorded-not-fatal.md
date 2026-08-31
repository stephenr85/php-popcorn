# ADR-0001 — A shadowed entry is recorded, not fatal

Status: accepted
Date: 2026-08-31

Registry-kernel ticket 73 §1 — tracker:
`~/Workspaces/splicewire-ecosystem/.scratch/rushing/laravel-popcorn/registry-kernel/tickets/73-conforming-is-gated-and-describing-is-not.md`.

## Context

`RegistryIndex::describe()` refused a root that would make an already-registered entry unreachable
through the index. Two described registries may nest — `beam.particle` and
`beam.particle.fragments.ops` both being described is legal, and `routeTo()` resolves by longest
prefix — but an entry spelled *under* the deeper root while held by the shallower registry has two
answers depending on which door a caller enters. Ticket 26 D5 chose to make that loud rather than to
define a winner, and it was loud by throwing `Exceptions\ShadowedRegistryKey` out of `describe()`.

Three things made that the wrong instrument.

**1. Whether it fires is a fact about the host, not about either declaration.** The same pair of
packages overlaps at one install and does not at another, entirely according to which providers
loaded. The estate's standing rule — *a check whose answer depends on the host must not throw* — was
written after exactly this shape stopped `~/Herd/tower` booting on a different check. The two
declarations are each individually legal; only the composition is not, and neither package's author
could have known.

**2. It was already the weaker half of a check that has a stronger half elsewhere.** A registry is
usually described in a provider's `register()`/`boot()` and *filled* by registrars afterwards, so at
the moment `describe()` looks, the colliding entry usually does not exist yet. The kernel's own
docblock has said so since ticket 26. The instrument that sees the rest is
`Splicewire\Beam\Doctor\RegistryConformanceAudit::shadowedEntries()`, which reads the live index
after boot, reports a strict superset of what the describe-time walk can see, and **gates**. So the
throw was never what caught the estate's shadowing; it caught the earliest-visible fraction, at the
price of being fatal.

**3. It blocks ticket 73's automatic describe pass.** If `#[IsRegistry]` becomes the single authoring
act and a pass describes every declared registry, then any overlap anywhere in the estate becomes a
boot failure at every host that composes both packages, simultaneously. Recording had to land first,
as its own step, or the collapse ships a fleet-wide boot hazard.

## Decision

**`describe()` records the shadowing and proceeds.** `assertUnshadowed()` becomes
`recordShadowing()`; each entry that goes dark is appended as a `Shadowed` record carrying both roots,
the registrant of the describe that created the overlap, and a monotonic sequence; the records are
read back through `RegistryIndex::shadowed(?root)`. `Exceptions\ShadowedRegistryKey` is **deleted**,
not deprecated — full cutover, per the map's standing preference.

This is deliberately the same trade, in the same shape, that ticket 34 made for duplicate **roots**
and ticket 48 landed: see `Superseded`, which `Shadowed` mirrors field for field. Detectability is
preserved; only fatality is traded.

Two details that are decisions rather than mechanics:

- **Every overlapping entry is recorded, not just the first.** The throwing version stopped at one
  because it was dying anyway. A report naming one of five and stopping reproduces, inside the
  replacement, the exact complaint that retired the throw.
- **Records are pruned when either of their two roots is forgotten**, driven by what is described
  rather than by which registrant was unwound, so `forget()` and `forgetBy()` cannot diverge. A record
  outliving its condition reports an overlap a reader can no longer find in the index, which is
  indistinguishable from the reader being broken.

## Considered options

- **Keep the throw and exempt the automatic pass.** Rejected: the throw would then fire only on the
  hand-written call sites the pass is meant to replace, i.e. it would become a check whose reach
  shrinks to zero as the cutover proceeds — the reach-before-precision failure this map has already
  paid for once in `SchemaKeyIndex`.
- **Define a winner (deeper root wins) and say nothing.** Rejected on ticket 26 D5's original
  reasoning, which this ADR does not reopen: a silent precedence rule makes `pop()` and
  `$shallow->has()` disagree with nothing announcing it.
- **Record it in `superseded()` rather than a new accessor.** Rejected: a supersession is one key with
  a displaced *entry*; a shadowing is one key claimed by two *registries* and nothing is displaced —
  both stores still hold what they held. Folding them would make "several entries under one key" and
  "one key with two owners" the same reading, which is the collapse ticket 01 D9 already refused for
  `Superseded` itself.

## Consequences

- A host with overlapping roots now boots. The overlap is reported by beam's gating conformance audit,
  and by `RegistryIndex::shadowed()` for anything that does not have beam.
- **`shadowed()` returning `[]` is not evidence of a clean estate** — it only ever sees overlaps that
  existed at the moment one of the two registries was described. Its docblock says so, because an
  empty list from a reader that could not look is this estate's signature defect. Read it for the
  provenance a post-boot scan cannot reconstruct; read the audit for coverage.
- `ShadowedRegistryKey` is gone from the package's public surface. It had no consumer outside
  `php-popcorn`'s own `src/` and tests (swept across all three vendors' `src/`, plus `~/Herd/*/app`).
  Consuming hosts' composer classmaps carry a stale entry until `composer dump-autoload` runs; nothing
  autoloads the name, so nothing fatals on it.
