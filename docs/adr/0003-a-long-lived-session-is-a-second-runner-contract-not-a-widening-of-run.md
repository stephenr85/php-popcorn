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

## Amendment 2026-11-17 (independent Codex review ff28ff40, REVISE)

Two consequences above were incomplete in the first implementation, and are now part of the decision:

- **Acquisition is total.** `ProcessSession::start()` validates every launch string, since a NUL byte makes
  `proc_open` throw. It creates its stderr file itself, by exclusive create rather than through `tempnam()`'s fallback
  directory. Any throwable, or a process started without both pipes, releases everything acquired: the child is
  killed and reaped, pipes and handles are closed, the file is unlinked. The result is a `RefusedSession`.
- **Diagnostics are bounded in memory.** `close()` reads only the stderr tail, by seeking from the file's size. Long
  sessions compact the file on host calls. **Residual boundary:** between two host calls the file on disk is not
  bounded, and bytes written during a compaction may be lost. When that happens, `stderrTruncated` is true.

A substrate must also never pass a granted credential through a launched process's argv. The bubble session delivers
the Grant's env as the launched process's exact environment.

## Amendment 2026-11-17 (root ruling 44be5842, popcorn-runner-rehome 03 unit 2)

**Delivery is exact.** A session starts its child with exactly the Grant's environment, and nothing else. That is the
invariant, and no check may relax it.

**One observed runtime addition is allowed in checks, and the list is closed.** A check usually reads what the child's
own code sees: `getenv()` in a PHP child. On macOS a PHP child has been OBSERVED to see `__CF_USER_TEXT_ENCODING` there,
although the variable was not handed to it. A names-only primitive control on the tots Mac (QA 6667105e, report
8ba1c183) found it in both parent conditions. The control did not see it in the environment that child was started
with. That reading rests on an assumption about what `ps -E` shows, and the control is invalid as a whole by its own
rule, because the Apple-binary children's start environment could not be read.

This records an observed post-exec runtime behaviour, nothing more. It does not establish that the platform injects
the name at spawn. It does not attribute the name to any library; that trace is a follow-up nomination.

`Rushing\Popcorn\Runner\RuntimeEnvAdditions::OBSERVED` holds the list: PHP, Darwin, `__CF_USER_TEXT_ENCODING`. A check
removes only that name, only for a PHP child on Darwin, and only when the Grant did not carry it. The check then
requires exact equality with the Grant, so any other extra name, on any platform, still fails. There is no pattern,
no allow-any and no skip. A new entry needs a new ruling, and the test that pins the list changes with it.

## Amendment 2026-11-17 (root ruling cb7f7936, PRR03 P4: retained stderr capped per host call)

This replaces the first amendment's "Diagnostics are bounded in memory" mechanism. That one wrote the kept tail back into
the file, and its residual is restated below.

- **The host never writes into the stderr file, and reads it through its own handle.** The child keeps its `O_APPEND`
  descriptor. The host opens a separate read-only handle, its own open file description with its own offset, so the
  child's appends cannot move what the host reads.
- **A compaction starts a new epoch.** On `send`, `receive` or `isRunning`, once the file passes
  `STDERR_COMPACT_BYTES`, the host keeps the epoch's last `STDERR_TAIL_BYTES` in memory and truncates the file to 0.
  Nothing is written back, so the child's next append begins the new epoch in true order: retained bytes are never
  reordered.
- **Loss is possible on every compaction, and is reported.** A byte the child, or a descendant sharing its stderr,
  writes between the compaction's size read and its truncate is destroyed unseen. The host cannot observe that window,
  so every compaction sets `Result::$stderrLossPossible`. Not seeing the size change is never taken as proof of no loss.
- **The counts are lower bounds.** `stderrBytesObservedAtLeast` is the sum of each truncated epoch's size plus the final
  size. `stderrBytesOmittedAtLeast` is that minus the child bytes returned, so it includes the final trim at close. A
  held copy is never written back and so never counted twice. Neither field is an exact total. Both are set only by a
  session; a one-shot `run()` Result serialises exactly as before.
- **The marker.** When anything was omitted, or loss is possible, `stderr` begins with one ASCII line,
  `[popcorn: stderr truncated: at least N of at least M observed bytes omitted; R bytes retained in write order…]`,
  extended with the possible-loss clause when it applies. A `[popcorn: possible gap]` line separates held bytes from
  the final epoch's, but only when both are returned; a possible loss after the held tail is otherwise carried by the
  marker's possible-loss clause. The whole string, marker included, is at most `STDERR_TAIL_BYTES`. The child's bytes
  appear in the order written, but are not promised contiguous once anything was omitted.
- **Unreadable is never empty.** If the stderr size or bytes cannot be read at close, the Result does not report
  empty-and-complete. Its `stderr` is one explicit `[popcorn: stderr could not be read at close; …]` line,
  `stderrTruncated` is true, everything observed so far counts as omitted (still lower bounds), and `error` says
  closing failed.
- **Residual boundary: a per-call cap, NOT a continuous disk bound.** Between two host calls a child can grow the file
  on disk without limit, and after a compaction it appends again. Memory stays a small constant multiple of the tail.
