<?php

namespace Rushing\Popcorn\Ladders;

/**
 * Runs rungs strongest-first, taking the first result that clears the
 * acceptance threshold; weaker rungs are tried only when stronger ones abstain
 * or fall short. Returns null when every rung declines — the caller's reviewer
 * floor. Per-region demotion (a clean front resolved by the strong rung, an
 * ambiguous tail by a weaker one) is just calling climb per region.
 */
class Ladder
{
    /** @var Rung[] */
    private array $rungs;

    public function __construct(
        Rung ...$rungs,
    ) {
        $this->rungs = $rungs;
    }

    /** @param  array<string, mixed>  $input  the same shape every {@see Rung::attempt()} receives */
    public function climb(array $input, float $acceptAbove = 0.0): ?RungResult
    {
        foreach ($this->rungs as $rung) {
            $result = $rung->attempt($input);

            if ($result !== null && $result->confidence >= $acceptAbove) {
                return $result;
            }
        }

        return null;
    }

    /** @return string[] rung names, strongest-first */
    public function rungs(): array
    {
        return array_map(fn (Rung $s) => $s->name(), $this->rungs);
    }
}
