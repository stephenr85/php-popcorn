<?php

namespace Rushing\Popcorn\Runner;

use Rushing\Popcorn\Contracts\SessionRunner;

/**
 * One launched (or refused) session from a {@see SessionRunner} (php-popcorn ADR-0003).
 *
 * Two states a caller must never confuse:
 *
 * - **refused** — {@see refusal()} is a Result (`SubstrateUnavailable`, `GrantDenied`, …). Nothing was
 *   started: `isRunning()` is false, `send()` is false, `receive()` is null, and `close()` returns that
 *   same refusal.
 * - **live** — `refusal()` is null. Lines go in with {@see send()} and come out with {@see receive()}
 *   (one newline-terminated frame per call, the line-delimited JSON-RPC framing a stdio MCP server
 *   speaks). When the child exits by itself, `receive()` returns null and `isRunning()` turns false.
 *
 * {@see close()} is total and idempotent: it always returns the session's terminal Result, the
 * first call does the teardown, and every later call returns that same Result.
 *
 * @api
 */
interface Session
{
    /** The Result that refused the open, or null for a session that was started. */
    public function refusal(): ?Result;

    /** Whether the child process is still alive (always false for a refused or closed session). */
    public function isRunning(): bool;

    /** The host pid of the launched process (the substrate wrapper's pid under a sandbox), or null. */
    public function pid(): ?int;

    /** Whether the session runs inside an enforcing substrate. False on a refusal and on a dev degrade. */
    public function sandboxed(): bool;

    /** The effective grant the session was opened under, reflected back for audit. */
    public function grant(): Grant;

    /**
     * Write one frame (a trailing newline is added when missing). False when the session is refused,
     * closed, or its stdin is gone — never a throw.
     */
    public function send(string $line): bool;

    /** Read one frame without its trailing newline; null at end of stream or when there is no live session. */
    public function receive(): ?string;

    /** End the session and return its terminal Result. Total and idempotent. */
    public function close(): Result;
}
