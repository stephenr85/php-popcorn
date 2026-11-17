# ADR-0003 — A long-lived session is a second Runner contract, not a widening of `run()`

Status: accepted
Date: 2026-11-17

Popcorn-runner-rehome ticket 03, owner ruling 2026-11-12 (route (a), a session-capable runner) — tracker:
`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/splicewire-app/popcorn-runner-rehome/issues/03-stdio-mcp-transport-to-bwrap-grant.md`.

## Context

`Runner::run(Manifest, Grant, array $input): Result` is one-shot: the input goes in on stdin, the
process ends, one total `Result` comes back. The flagship's stdio MCP transport needs something else:
a server process that stays up and answers many line-delimited JSON-RPC requests over one pipe pair,
kept warm by a pool. Before this decision it was launched by a raw `proc_open` behind a hand-rolled,
unenforced `sandbox_wrapper` config prefix, outside any Runner, Grant or Result.

Two routes were weighed. (b) derives a `bwrap` launch prefix from the Grant and keeps launching outside
the Runner seam. That amends the rehome map's destination (every execution site resolves through a
Runner with a uniform total `Result`). The owner took (a).

## Decision

**A session is its own kernel contract, `SessionRunner::open(Manifest, Grant): Session`, beside the
unchanged one-shot `Runner`.** It reuses every Runner noun: the same `Manifest` says what to launch,
the same effective `Grant` says what it may touch, and the same total `Result` is the verdict, at
the end of the session rather than the end of a run.

1. **`Runner` is not widened.** Every existing implementor (the substrates, the test doubles in
   consumer packages) keeps compiling, and a one-shot caller never sees session concepts.
2. **Open never throws for a launch outcome.** A session that cannot start comes back as a
   `RefusedSession` whose `refusal()` is the Result saying why (`SubstrateUnavailable`,
   `GrantDenied`, …). A live session has `refusal() === null`. The two are never confused.
3. **`close()` is total and idempotent.** It always returns the terminal Result. A child that died by
   itself closes as `NonZeroExit` with its real code. A child the host had to stop closes as `Success`
   with the signal recorded. Later calls return the same Result.
4. **The kernel ships the process mechanics, not the isolation.** `ProcessSession` runs an argv with
   an exact environment (no host passthrough) and keeps the lifecycle honest. Whether that argv is
   `bwrap … --` or a dev degrade, and so whether the session is `sandboxed`, is the substrate's call.

## Consequences

- A substrate opts in by implementing `SessionRunner` (the bubble `BwrapRunner` first). A backend
  that cannot host a two-way process, such as a one-shot wasm module, simply does not implement it.
- The session's value travels over the stream, so its terminal `Result` carries lifecycle
  (exit, signal, stderr tail, `sandboxed`, `wallMs`) and an empty `output()`.
- A per-frame read timeout is not part of the contract yet. `receive()` blocks like the transport it
  replaces; a timeout would be a later, additive change.
