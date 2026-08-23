<?php

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Filled;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registrars\ArrayRegistrarCache;
use Rushing\Popcorn\Registries\Registrars\AttributeRegistrar;
use Rushing\Popcorn\Registries\Registrars\CachedRegistrar;
use Rushing\Popcorn\Registries\Registrars\ConfigRegistrar;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\AnnotatedResource;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\ScannedResource;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\SelfKeyingEntry;

/**
 * The registrars (registry-kernel ticket 24, landing ticket 07 D2–D13).
 *
 * The two assertions that carry the most weight are not about a registrar's own behaviour: that a
 * registrar always names its registrant (D13, because a `null` there silently degrades the miss
 * diagnostics and the supersession record), and that attach-then-hand-register leaves the
 * hand-registered entry winning by `OnDuplicate::Supersede` alone (D9, the claim that dissolved
 * ticket 19 — asserted here rather than argued in a paragraph).
 */
function filled(string $root = 'beam.renderings'): BasicRegistry
{
    return new BasicRegistry(new IsRegistry(
        root: $root,
        of: 'test entries',
        arity: RegistryArity::PickOne,
    ));
}

it('fills from an already-read array and never reaches for a config function', function () {
    $registry = filled();

    $registry->attach(new ConfigRegistrar(
        ['table' => 'TableRendering', 'card' => 'CardRendering'],
        'beam.core.renderings',
    ));

    expect(array_map('strval', $registry->keys()))
        ->toBe(['beam.renderings.table', 'beam.renderings.card'])
        ->and($registry->resolve('table'))->toBe('TableRendering');

    // The proof that it takes data rather than a key it would have to resolve. Comments stripped,
    // because the docblock necessarily NAMES `config()` to say it does not call it — and asserting
    // against raw source would make the class un-documentable.
    expect(php_strip_whitespace((new ReflectionClass(ConfigRegistrar::class))->getFileName()))
        ->not->toContain('config(');
});

it('names the config key as the registrant on every write', function () {
    $registry = filled();
    $registry->attach(new ConfigRegistrar(['table' => 'TableRendering'], 'beam.core.renderings'));

    $superseded = $registry
        ->register('table', 'HandRegistered', by: 'splicewire/laravel-beam')
        ->superseded('table');

    expect($superseded)->toHaveCount(1)
        ->and($superseded[0]->by)->toBe('beam.core.renderings');
});

it('renders each registrar source in a form that says where to contribute', function () {
    expect((new ConfigRegistrar([], 'beam.core.renderings'))->source())
        ->toBe('config beam.core.renderings')
        ->and((new AttributeRegistrar([__DIR__.'/Fixtures'], ScannedResource::class))->source())
        ->toBe('#[ScannedResource] under '.__DIR__.'/Fixtures');
});

it('scans for an attribute, projects each class, and keys off the projected entry', function () {
    $registry = filled('beam.resources');

    $registry->attach(new AttributeRegistrar(
        [__DIR__.'/Fixtures'],
        ScannedResource::class,
        project: fn (string $class) => new SelfKeyingEntry($class::KEY, $class),
    ));

    expect(array_map('strval', $registry->keys()))->toBe(['beam.resources.invoices'])
        ->and($registry->resolve('invoices'))->toBeInstanceOf(SelfKeyingEntry::class)
        ->and($registry->resolve('invoices')->from)->toBe(AnnotatedResource::class);
});

it('sets the scanned class own FQCN as the registrant, not the path it was found under', function () {
    $registry = filled('beam.resources');

    $registry->attach(new AttributeRegistrar(
        [__DIR__.'/Fixtures'],
        ScannedResource::class,
        project: fn (string $class) => new SelfKeyingEntry($class::KEY, $class),
    ));

    $superseded = $registry
        ->register('invoices', 'hand', by: 'splicewire/laravel-beam')
        ->superseded('invoices');

    expect($superseded[0]->by)
        ->toBe(AnnotatedResource::class);
});

it('refuses to invent a key from a class name when nothing says what the key is', function () {
    $registry = filled('beam.resources');

    $registrar = new AttributeRegistrar([__DIR__.'/Fixtures'], ScannedResource::class);

    expect(fn () => $registry->attach($registrar))
        ->toThrow(InvalidArgumentException::class, 'would be a guess');
});

it('takes an explicit key callable ahead of the self-keying seam', function () {
    $registry = filled('beam.resources');

    $registry->attach(new AttributeRegistrar(
        [__DIR__.'/Fixtures'],
        ScannedResource::class,
        key: fn (string $class) => 'explicit',
    ));

    expect(array_map('strval', $registry->keys()))->toBe(['beam.resources.explicit']);
});

it('leaves the hand-registered entry winning by Supersede alone — no tier, no branch', function () {
    // Ticket 07 D9, and the assertion that dissolves ticket 19. The owner attaches at its own boot;
    // a consumer provider hand-registers afterwards. Nothing arbitrates but arrival order.
    $registry = filled();

    $registry->attach(new ConfigRegistrar(['table' => 'from config'], 'beam.core.renderings'));
    $registry->register('table', 'from a consumer provider', by: 'splicewire/tower');

    expect($registry->resolve('table'))->toBe('from a consumer provider')
        ->and($registry->superseded('table')[0]->entry)->toBe('from config');

    // And the inverse, to pin that it really is ordering and not a rule about registrars: fill LAST
    // and the registrar wins, which is exactly why lazy-on-first-read was rejected.
    $inverted = filled();
    $inverted->register('table', 'from a consumer provider', by: 'splicewire/tower');
    $inverted->attach(new ConfigRegistrar(['table' => 'from config'], 'beam.core.renderings'));

    expect($inverted->resolve('table'))->toBe('from config');
});

it('fills on attach, so the entries are there before anything reads', function () {
    $registry = filled();

    expect($registry->keys())->toBe([]);

    $registry->attach(new ConfigRegistrar(['table' => 'TableRendering'], 'beam.core.renderings'));

    expect($registry->has('table'))->toBeTrue();
});

it('exposes its registrars generically, for the index derived source column', function () {
    $registry = filled();
    $registrar = new ConfigRegistrar(['table' => 'x'], 'beam.core.renderings');

    $registry->attach($registrar);

    expect($registry)->toBeInstanceOf(Filled::class)
        ->and($registry->registrars())->toBe([$registrar])
        ->and(array_map(fn (Registrar $r) => $r->source(), $registry->registrars()))
        ->toBe(['config beam.core.renderings']);
});

it('replays a cached registrar writes instead of re-reading the source', function () {
    $counting = new class implements Registrar
    {
        public int $reads = 0;

        public function fill(Registry $registry): void
        {
            $this->reads++;
            $registry->register('table', 'TableRendering', by: 'beam.core.renderings');
        }

        public function source(): string
        {
            return 'config beam.core.renderings';
        }
    };

    $cached = new CachedRegistrar($counting, new ArrayRegistrarCache);

    $first = filled();
    $second = filled();

    $first->attach($cached);
    $second->attach($cached);

    expect($counting->reads)->toBe(1)
        ->and($first->resolve('table'))->toBe('TableRendering')
        ->and($second->resolve('table'))->toBe('TableRendering')
        ->and($second->superseded('table'))->toBe([]);
});

it('replays the registrant and the ability alongside the entry', function () {
    $registrar = new class implements Registrar
    {
        public function fill(Registry $registry): void
        {
            $registry->register('payroll', 'PayrollRendering', by: 'beam.core.renderings', ability: 'view-payroll');
        }

        public function source(): string
        {
            return 'config beam.core.renderings';
        }
    };

    $cache = new ArrayRegistrarCache;

    filled()->attach(new CachedRegistrar($registrar, $cache));

    $replayed = filled();
    $replayed->attach(new CachedRegistrar($registrar, $cache));

    $superseded = $replayed->register('payroll', 'hand', by: 'splicewire/tower')->superseded('payroll');

    expect($superseded[0]->by)->toBe('beam.core.renderings');
});

it('lets the wrapped registrar read the registry it is actually filling', function () {
    // The recorder decorates rather than substitutes: a registrar checking has() before writing must
    // see the real state, or a cache would change what it decides to write.
    $seen = null;

    $registrar = new class($seen) implements Registrar
    {
        public function __construct(public mixed &$seen) {}

        public function fill(Registry $registry): void
        {
            $this->seen = $registry->has('table');
            $registry->register('card', 'CardRendering', by: 'beam.core.renderings');
        }

        public function source(): string
        {
            return 'config beam.core.renderings';
        }
    };

    $registry = filled()->register('table', 'already here', by: 'splicewire/laravel-beam');
    $registry->attach(new CachedRegistrar($registrar, new ArrayRegistrarCache));

    expect($registrar->seen)->toBeTrue();
});

it('keeps the wrapped source unannotated, because caching is not where entries come from', function () {
    $cached = CachedRegistrar::inMemory(new ConfigRegistrar([], 'beam.core.renderings'));

    expect($cached->source())->toBe('config beam.core.renderings')
        ->and($cached->registrar())->toBeInstanceOf(ConfigRegistrar::class);
});
