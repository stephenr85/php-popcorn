<?php

use Rushing\Popcorn\Ladders\Rung;
use Rushing\Popcorn\Ladders\Ladder;
use Rushing\Popcorn\Ladders\RungResult;

/** A test rung: yields a fixed result, or abstains when $abstain. */
function rung(string $name, ?float $confidence): Rung
{
    return new class($name, $confidence) implements Rung
    {
        public function __construct(private string $n, private ?float $c) {}

        public function name(): string
        {
            return $this->n;
        }

        public function attempt(array $input): ?RungResult
        {
            return $this->c === null ? null : new RungResult($this->n.':'.($input['x'] ?? ''), $this->c, $this->n);
        }
    };
}

it('takes the first rung that produces a result', function () {
    $ladder = new Ladder(rung('exact', 0.9), rung('fuzzy', 0.6));

    $result = $ladder->climb(['x' => 'foo']);

    expect($result->rung)->toBe('exact')
        ->and($result->value)->toBe('exact:foo');
});

it('demotes past an abstaining rung to the next', function () {
    $ladder = new Ladder(rung('exact', null), rung('fuzzy', 0.6));

    expect($ladder->climb([])->rung)->toBe('fuzzy');
});

it('demotes past a rung that falls short of the acceptance threshold', function () {
    $ladder = new Ladder(rung('exact', 0.4), rung('fuzzy', 0.8));

    expect($ladder->climb([], acceptAbove: 0.5)->rung)->toBe('fuzzy');
});

it('returns null when every rung declines — the reviewer floor', function () {
    $ladder = new Ladder(rung('exact', null), rung('fuzzy', 0.3));

    expect($ladder->climb([], acceptAbove: 0.5))->toBeNull()
        ->and($ladder->rungs())->toBe(['exact', 'fuzzy']);
});
