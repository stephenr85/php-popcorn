<?php

namespace Rushing\Popcorn\Runner;

use InvalidArgumentException;
use Rushing\Popcorn\Contracts\SessionRunner;

/**
 * A {@see Session} that was never started — the open-time verdict of a {@see SessionRunner} that
 * could not, or would not, launch. It carries the refusing Result and answers every call as a dead
 * session does: nothing runs, nothing is written, `close()` returns the refusal.
 *
 * @api
 */
class RefusedSession implements Session
{
    public function __construct(
        private Result $refusal,
        private Grant $grant = new Grant,
    ) {
        if ($refusal->successful()) {
            throw new InvalidArgumentException('popcorn: a refused session needs a failed Result, not Success.');
        }
    }

    public function refusal(): Result
    {
        return $this->refusal;
    }

    public function isRunning(): bool
    {
        return false;
    }

    public function pid(): ?int
    {
        return null;
    }

    public function sandboxed(): bool
    {
        return false;
    }

    public function grant(): Grant
    {
        return $this->grant;
    }

    public function send(string $line): bool
    {
        return false;
    }

    public function receive(): ?string
    {
        return null;
    }

    public function close(): Result
    {
        return $this->refusal;
    }
}
