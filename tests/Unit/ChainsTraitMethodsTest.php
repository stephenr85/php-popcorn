<?php

use Rushing\Popcorn\Concerns\Chained;
use Rushing\Popcorn\Concerns\ChainsTraitMethods;
use Rushing\Popcorn\Concerns\TraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods as ChainsTraitMethodsContract;

/*
 * The trait-method chain: a composed class's TRAITS contribute steps, instead of the class
 * hand-listing every step it must remember to call.
 *
 * The fixtures below are the service-provider shape with the domain removed — a class that boots
 * several concerns, each concern a trait.
 */

trait ChainAlpha
{
    #[Chained('boot')]
    protected function bootAlpha(): string
    {
        return 'alpha';
    }

    #[Chained('filters')]
    protected function alphaFilters(): array
    {
        return ['a' => 1];
    }
}

trait ChainBeta
{
    /** The Eloquent convention — `{chain}{TraitBasename}`, no attribute. An existing trait joins unchanged. */
    protected function bootChainBeta(): string
    {
        return 'beta';
    }
}

trait ChainGamma
{
    /** Two links in one chain from ONE trait — the thing the naming convention structurally cannot express. */
    #[Chained('boot')]
    protected function gammaFirst(): string
    {
        return 'gamma-1';
    }

    #[Chained('boot')]
    protected function gammaSecond(): string
    {
        return 'gamma-2';
    }

    #[Chained('filters')]
    protected function gammaFilters(): array
    {
        return ['g' => 2];
    }
}

trait ChainNested
{
    use ChainAlpha;
}

trait ChainStatic
{
    #[Chained('boot')]
    protected static function bootStatically(): string
    {
        return 'static';
    }
}

class ChainHost implements ChainsTraitMethodsContract
{
    use ChainGamma;
    use ChainStatic;
    use ChainsTraitMethods;

    #[Chained('boot')]
    protected function hostOwnStep(): string
    {
        return 'host';
    }
}

class ChainConventionHost implements ChainsTraitMethodsContract
{
    use ChainBeta;
    use ChainsTraitMethods;
}

class ChainNestedHost implements ChainsTraitMethodsContract
{
    use ChainNested;
    use ChainsTraitMethods;
}

class ChainBareHost implements ChainsTraitMethodsContract
{
    use ChainsTraitMethods;
}

it('runs every attributed link in the chain', function () {
    $results = (new ChainHost)->chainTraitMethods('boot');

    expect($results)->toHaveKeys(['gammaFirst', 'gammaSecond', 'bootStatically', 'hostOwnStep'])
        ->and($results['gammaFirst'])->toBe('gamma-1')
        ->and($results['gammaSecond'])->toBe('gamma-2');
});

it('keys results by method name so a caller can tell which trait contributed what', function () {
    expect((new ChainHost)->chainTraitMethods('boot')['gammaSecond'])->toBe('gamma-2');
});

it('lets ONE trait contribute two links to one chain', function () {
    // The naming convention cannot express this: `boot{TraitBasename}` is one method per trait per chain.
    $results = (new ChainHost)->chainTraitMethods('boot');

    expect(array_filter(array_keys($results), fn (string $m) => str_starts_with($m, 'gamma')))
        ->toHaveCount(2);
});

it('honours the Eloquent naming convention so an existing trait joins unchanged', function () {
    expect((new ChainConventionHost)->chainTraitMethods('boot'))->toBe(['bootChainBeta' => 'beta']);
});

it('invokes a static link statically and an instance link on the instance', function () {
    $results = (new ChainHost)->chainTraitMethods('boot');

    expect($results['bootStatically'])->toBe('static')
        ->and($results['hostOwnStep'])->toBe('host');
});

it('reaches a protected link without setAccessible', function () {
    // PHP 8.1 made reflection access the default; this package requires 8.3. A trait's contribution
    // stays protected rather than being forced public to be callable.
    expect((new ChainConventionHost)->chainTraitMethods('boot'))->not->toBeEmpty();
});

it('runs a class-declared link LAST, so a class can act on what its traits already did', function () {
    $order = array_keys((new ChainHost)->chainTraitMethods('boot'));

    expect(array_key_last($order))->toBe(array_search('hostOwnStep', $order, true));
});

it('walks nested traits', function () {
    expect(TraitMethods::using(ChainNestedHost::class))->toContain(ChainAlpha::class)
        ->and((new ChainNestedHost)->chainTraitMethods('boot'))->toBe(['bootAlpha' => 'alpha']);
});

it('is a no-op for a class whose traits contribute nothing', function () {
    expect((new ChainBareHost)->chainTraitMethods('boot'))->toBe([]);
});

it('keeps two chains on one class independent', function () {
    $host = new ChainHost;

    expect($host->chainTraitMethods('filters'))->toBe(['gammaFilters' => ['g' => 2]])
        ->and($host->chainTraitMethods('boot'))->not->toHaveKey('gammaFilters');
});

it('forwards arguments verbatim to every link', function () {
    $host = new class implements ChainsTraitMethodsContract
    {
        use ChainsTraitMethods;

        #[Chained('boot')]
        protected function echoes(string $a, int $b): string
        {
            return $a.':'.$b;
        }
    };

    expect($host->chainTraitMethods('boot', 'x', 7))->toBe(['echoes' => 'x:7']);
});

it('collects array contributions into one merged map', function () {
    $host = new class implements ChainsTraitMethodsContract
    {
        use ChainAlpha;
        use ChainGamma;
        use ChainsTraitMethods;
    };

    expect($host->collectTraitMethods('filters'))->toBe(['a' => 1, 'g' => 2]);
});

/*
 * ⚠️ Ordering is DECLARED, never positional — and this is not a style preference.
 *
 * `vendor/bin/pint` ships the Laravel preset's `ordered_traits` fixer, which sorts a class's `use`
 * statements ALPHABETICALLY. It rewrote the fixtures at the top of THIS file the first time it ran over
 * them, and it rewrote this block's fixtures too when they first tried to hold a non-alphabetical order.
 * A chain relying on `use` order would be silently re-sequenced by a formatter on an unrelated commit,
 * with nothing failing.
 *
 * So the fixtures below are named so that ALPHABETICAL order and DECLARED order DISAGREE: `ChainAlwaysLast`
 * sorts first and runs last. Pint may reorder the `use` statements freely — that is the point — and the
 * assertion still pins the declared sequence. This is the one arrangement the formatter cannot neutralize.
 */

trait ChainAlwaysLast
{
    #[Chained('sequenced', order: 300)]
    protected function lateStep(): string
    {
        return 'late';
    }
}

trait ChainZeroethFirst
{
    #[Chained('sequenced', order: 10)]
    protected function earlyStep(): string
    {
        return 'early';
    }
}

it('runs links in DECLARED order even when it disagrees with alphabetical trait order', function () {
    $host = new class implements ChainsTraitMethodsContract
    {
        use ChainAlwaysLast;
        use ChainsTraitMethods;
        use ChainZeroethFirst;
    };

    // Alphabetically ChainAlwaysLast < ChainZeroethFirst, so a positional mechanism runs 'late' first.
    expect(array_keys($host->chainTraitMethods('sequenced')))->toBe(['earlyStep', 'lateStep']);
});

it('lets a declared order override the class-trails-its-traits default', function () {
    $host = new class implements ChainsTraitMethodsContract
    {
        use ChainGamma;
        use ChainsTraitMethods;

        #[Chained('boot', order: 1)]
        protected function firstDespiteBeingOnTheClass(): string
        {
            return 'first';
        }
    };

    expect(array_key_first($host->chainTraitMethods('boot')))->toBe('firstDespiteBeingOnTheClass');
});

it('never returns one method twice', function () {
    // A link matching BOTH the convention and the attribute is one link, not two.
    $host = new class implements ChainsTraitMethodsContract
    {
        use ChainDoubleDeclared;
        use ChainsTraitMethods;
    };

    expect($host->chainTraitMethods('boot'))->toHaveCount(1);
});

trait ChainDoubleDeclared
{
    #[Chained('boot')]
    protected function bootChainDoubleDeclared(): string
    {
        return 'once';
    }
}
