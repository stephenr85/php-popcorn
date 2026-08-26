<?php

use Rushing\Popcorn\Registries\AbsoluteUriKey;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RelativeUriKey;
use Rushing\Popcorn\Registries\Rootable;
use Rushing\Popcorn\Tests\Unit\Registries\Fixtures\NamespaceUriKey as ConsumerOwnedKey;

/**
 * The two kernel URI key types and the widened door (registry-kernel ticket 64, ruled by 58 D2/D3).
 *
 * The thing under test is a DISTINCTION, not a feature: `BasicRegistry::door()` used to ask
 * `instanceof Key` while its docblock claimed to be asking "can the kernel construct this?" — canonical
 * standing in for addressable. Three populations now fall out of one rule, and each one is pinned here
 * because the failure mode is silence: a key that quietly stops being root-stamped strands its entries
 * where `matches(root)` cannot see them, and the owning package's own suite looks identical either way
 * (asset 15 §3's trap).
 */
function uriKeyRegistry(string $root = 'nav.kinds'): BasicRegistry
{
    return new BasicRegistry(new IsRegistry(
        root: $root,
        of: 'nav kinds',
        arity: RegistryArity::PickOne,
        onDuplicate: OnDuplicate::Supersede,
        optionality: Optionality::Optional,
    ));
}

describe('RelativeUriKey is a parser over the one keyspace, not a second one', function () {
    it('segments a slashed path byte-identically to its dotted spelling', function () {
        // The acceptance criterion, stated as an identity rather than a shape: if these ever drift,
        // `nav/link` and `nav.link` become two addresses for one thing and the registry holds both.
        expect(RelativeUriKey::of('nav/link')->segments())->toBe(Key::of('nav.link')->segments());
    });

    it('renders back to the slashed spelling, so the translation is lossless both ways', function () {
        // D5's reason this row needed a kernel type rather than a one-way keyFor(): `nav/link` is also
        // the `kind` discriminator clients parse.
        $key = RelativeUriKey::of('acme/press-release');

        expect((string) $key)->toBe('acme/press-release')
            ->and((string) RelativeUriKey::fromSegments($key->segments()))->toBe('acme/press-release');
    });

    it('rejects an absolute URI outright rather than accepting it in a second mode', function (string $absolute) {
        // Relative-only is what makes "if it constructed, it stamps" total. A dual-mode type would lose
        // root-stamping depending on its input — success reported by not running.
        expect(fn () => RelativeUriKey::of($absolute))->toThrow(InvalidRegistryKey::class);
    })->with([
        'https://app.splicewire.com/json-schemas/grounding',
        'http://example.test/a/b',
        'urn:isbn:0451450523',
        '//app.splicewire.com/nav/link',
        '/nav/link',
    ]);

    it('admits no charset the dotted spelling would not', function (string $illegal) {
        // The slash is a joiner being translated, not a licence.
        expect(RelativeUriKey::tryParse($illegal))->toBeNull();
    })->with(['nav/Link', 'a//b', 'nav/', '/nav', '', 'nav/li nk', 'nav/li.nk', 'nav/_link']);

    it('equals its dotted twin, because equality is segments and never the rendering', function () {
        expect(RelativeUriKey::of('nav/link')->equals(Key::of('nav.link')))->toBeTrue()
            ->and(Key::of('nav.link')->equals(RelativeUriKey::of('nav/link')))->toBeTrue();
    });
});

describe('the door addresses what the kernel owns and leaves alone what it does not', function () {
    it('stamps a relative slashed key and stores it as a dotted address', function () {
        $registry = uriKeyRegistry()->register(RelativeUriKey::of('nav/link'), 'link-kind');

        // The point of the whole change: it is reachable by its global address, matches(root) sees it,
        // and nothing non-dotted survived the door for an enumeration to trip over.
        expect($registry->has('nav.kinds.nav.link'))->toBeTrue()
            ->and($registry->resolve(RelativeUriKey::of('nav/link')))->toBe('link-kind')
            ->and(array_map('strval', $registry->keys()))->toBe(['nav.kinds.nav.link'])
            ->and($registry->keys()[0])->toBeInstanceOf(Key::class);
    });

    it('yields a Key whether or not stamping was needed', function () {
        // The dual-mode defect one tier down: a storage type that depended on whether the caller had
        // already spelled the root would make two registries of one.
        $already = uriKeyRegistry()->register(RelativeUriKey::of('nav/kinds/link'), 'link-kind');

        expect($already->keys()[0])->toBeInstanceOf(Key::class)
            ->and((string) $already->keys()[0])->toBe('nav.kinds.link');
    });

    it('is idempotent, so a value passing the door twice is stable', function () {
        $root = Key::of('nav.kinds');
        $once = Key::of('nav.link')->underRoot($root);

        expect((string) $once)->toBe('nav.kinds.nav.link')
            ->and((string) $once->underRoot($root))->toBe('nav.kinds.nav.link');
    });

    it('treats a zero-segment root as a no-op with no special case', function () {
        // RegistryIndex's root. Everything is under the root of the whole tree.
        expect((string) Key::of('beam.realm')->underRoot(Key::root()))->toBe('beam.realm');
    });

    it('leaves a consumer-owned key relative-forever, so the widening is opt-in by construction', function () {
        // Ticket 20 D3 and 13's refusal both still stand — narrowed, not reversed. The fixture is a
        // consumer's own RegistryKey and implements nothing of the kernel's.
        $key = ConsumerOwnedKey::of('https://schemastud.dev/ns/grounding');
        $registry = uriKeyRegistry('jsonns.namespaces')->register($key, 'handler');

        expect($key)->not->toBeInstanceOf(Rootable::class)
            ->and($registry->keys()[0])->toBe($key)
            ->and((string) $registry->keys()[0])->toBe('https://schemastud.dev/ns/grounding');
    });
});

describe('AbsoluteUriKey declines rather than being unable', function () {
    it('implements Rootable and still comes back unstamped', function () {
        // The distinction ticket 64 asked to be decided and documented rather than incidental: it is
        // kernel-owned and therefore newly stampable, and stamping a dotted root onto one opaque URI
        // segment addresses nothing.
        $key = AbsoluteUriKey::of('https://app.splicewire.com/json-schemas/grounding');

        expect($key)->toBeInstanceOf(Rootable::class)
            ->and($key->underRoot(Key::of('schemas.json')))->toBe($key);
    });

    it('is one opaque segment, compared whole', function () {
        $uri = 'https://app.splicewire.com/json-schemas/grounding';

        expect(AbsoluteUriKey::of($uri)->segments())->toBe([$uri])
            ->and(AbsoluteUriKey::of($uri)->equals(AbsoluteUriKey::of($uri)))->toBeTrue()
            ->and((string) AbsoluteUriKey::of($uri))->toBe($uri);
    });

    it('refuses a relative path, so the two types partition their input', function (string $relative) {
        // Mirror of RelativeUriKey's refusal. Overlapping types would leave a caller unsure which it got.
        expect(fn () => AbsoluteUriKey::of($relative))->toThrow(InvalidRegistryKey::class);
    })->with(['nav/link', 'acme/press-release', '/nav/link', '', 'nav.link']);
});
