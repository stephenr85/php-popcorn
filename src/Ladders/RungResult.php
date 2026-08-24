<?php

namespace Rushing\Popcorn\Ladders;

/** What a rung produced: a value, how confident it is, and which rung found it. */
class RungResult
{
    public function __construct(
        public mixed $value,
        public float $confidence,
        public string $rung,
    ) {}
}
