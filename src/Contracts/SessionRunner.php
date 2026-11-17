<?php

namespace Rushing\Popcorn\Contracts;

use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Session;

/**
 * The long-lived, two-way sibling of the one-shot {@see Runner} (popcorn-runner-rehome ticket 03,
 * php-popcorn ADR-0003). `Runner::run()` is array-in/array-out: the input goes in on stdin, the
 * process ends, one {@see \Rushing\Popcorn\Runner\Result} comes back. A stdio MCP server cannot be
 * expressed that way — it stays up and answers many requests over one pipe pair — so it gets its own
 * contract rather than a widening of `run()`.
 *
 * Everything that makes a Runner a Runner carries over unchanged: the same {@see Manifest} says what
 * to launch, the same *effective* {@see Grant} says what it may touch (deny-by-default, resolved
 * host-side, never sourced here), and the same total `Result` is the verdict — at the END of the
 * session ({@see Session::close()}) rather than at the end of a single run. One implementation per
 * isolation-backend, never per language.
 *
 * @api
 */
interface SessionRunner
{
    /**
     * Launch the manifest's entrypoint as a session under the effective grant.
     *
     * Never throws for a launch outcome. A session that could not be opened (no substrate, a denied
     * grant, a spawn failure) comes back as a session whose {@see Session::refusal()} is the total
     * Result saying why; it was never started and nothing ran.
     */
    public function open(Manifest $manifest, Grant $grant): Session;
}
