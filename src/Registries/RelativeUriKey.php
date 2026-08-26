<?php

namespace Rushing\Popcorn\Registries;

use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;

/**
 * A relative path — `nav/link`, `acme/press-release`, `acme/beam-ext-foo` — read as an ordinary
 * registry address.
 *
 * ## It is a parser and a renderer, NOT a second keyspace
 *
 * `RelativeUriKey::of('nav/link')->segments()` is byte-identical to `Key::of('nav.link')->segments()`.
 * The slash is a **joiner being translated**, not a new grammar: every segment must still satisfy
 * {@see Key::SEGMENT_PATTERN}, so `nav/Link` and `a//b` are rejected exactly as their dotted spellings
 * would be. There is one keyspace in this kernel and this type does not open a second one — it lets a
 * wire that already spells identities with slashes reach into the one that exists.
 *
 * That is what makes the translation lossless in **both** directions, which registry-kernel ticket 58
 * D5 made the requirement for `NavKindRegistry`: `nav/link` is also the `kind` discriminator clients
 * parse, so a one-way `keyFor()` would not have been enough. Forward is {@see of()}; back is
 * {@see fromSegments()}, and the three hand-rolled translators D1 found (`ConduitCapabilityRegistry::
 * addressOf()`, `CapabilityLadder::resolveReference()`, `SchemaOrgRegistry::keyFor()`) collapse into
 * this pair.
 *
 * ## Relative-only, and that is the decision rather than a limitation
 *
 * An absolute URI is **rejected**, loudly. Two reasons, and the second is the one that matters:
 *
 * - It is genuinely unspellable. `https://app.splicewire.com/nav/link` has an authority containing
 *   dots, so as one segment it is illegal (`.` is the separator) and as three (`app`, `splicewire`,
 *   `com`) it is indistinguishable from an ordinary dotted address — two URIs on two hosts could
 *   collide with each other and with real keys.
 * - A **dual-mode type would silently lose root-stamping depending on its input**, which is this
 *   estate's recurring *"instrument that reports success by not running"* shape. Relative-only makes
 *   the guarantee total: **if it constructed, it stamps.** An absolute URI has its own type,
 *   {@see AbsoluteUriKey}, which declines stamping openly instead.
 *
 * ## It does not survive the door, and that is the point
 *
 * {@see underRoot()} hands back a {@see Key}. The wire keeps its slashes; the kernel stores an address.
 * A registry keyed this way therefore enumerates, routes, `matches(root)` and validates through
 * `ExistsInRegistry` like any dotted registry, because after the door there is nothing non-dotted left
 * — it is not a foreign-keyed registry in any sense the kernel can observe.
 *
 * ⚠️ The type is stable across the door in the sense that matters: it yields a `Key` **whether or not
 * stamping was needed**, never a `Key` sometimes and a `RelativeUriKey` other times. A storage type
 * that depended on whether the caller had already spelled the root would be the dual-mode defect above,
 * one tier down.
 */
class RelativeUriKey implements Rootable
{
    public const SEPARATOR = '/';

    /**
     * Any RFC 3986 scheme, plus the protocol-relative and absolute-path forms. Matched to REJECT — see
     * the class docblock. `\z` rather than `$` throughout this kernel, per {@see Key::SEGMENT_PATTERN}.
     */
    private const ABSOLUTE_PATTERN = '~^(?:[a-zA-Z][a-zA-Z0-9+.\-]*:|/)~';

    /** @param  list<string>  $segments */
    private function __construct(private array $segments) {}

    /** @throws InvalidRegistryKey */
    public static function parse(string $path): self
    {
        return static::tryParse($path) ?? throw new InvalidRegistryKey(
            "`{$path}` is not a legal relative registry path: expected slash-separated lowercase segments, "
                .'e.g. `nav/link`. Each segment obeys the ordinary key charset (groups of `[a-z0-9]` joined '
                .'by `-`, `_` or `:`, never leading, trailing or doubled), and an ABSOLUTE URI is refused '
                .'outright — its authority contains dots, which the keyspace reads as separators. Use '
                .'AbsoluteUriKey for those.'
        );
    }

    public static function tryParse(string $path): ?self
    {
        if ($path === '' || preg_match(self::ABSOLUTE_PATTERN, $path) === 1) {
            return null;
        }

        $segments = explode(static::SEPARATOR, $path);

        foreach ($segments as $segment) {
            if (preg_match(Key::SEGMENT_PATTERN, $segment) !== 1) {
                return null;
            }
        }

        return new self($segments);
    }

    /** Coerce the `RegistryKey|string` union, parsing a string as a relative path. Passthru for a key. */
    public static function of(RegistryKey|string $path): RegistryKey
    {
        return $path instanceof RegistryKey ? $path : static::parse($path);
    }

    /**
     * The reverse direction: an address rendered back as the slashed path a client sent.
     *
     * This is the half that makes the translation lossless. Pair it with `Registry::relativeKeys()`
     * when the registry stamps a root — the root is the kernel's address for the branch, not part of
     * the identity the wire spelled.
     *
     * @param  list<string>  $segments
     */
    public static function fromSegments(array $segments): self
    {
        return static::parse(implode(static::SEPARATOR, $segments));
    }

    /** @return list<string> */
    public function segments(): array
    {
        return $this->segments;
    }

    public function equals(RegistryKey $other): bool
    {
        return $this->segments === $other->segments();
    }

    /**
     * Always a {@see Key} — see the class docblock's "it does not survive the door".
     *
     * The stamping RULE itself is not restated here; it is delegated to `Key` so there is exactly one
     * place that decides what "already under the root" means. Registry-kernel ticket 41 D11's *"a
     * restated argument is a place to drift"*, applied before the drift rather than after it.
     */
    public function underRoot(Key $root): RegistryKey
    {
        return Key::fromSegments($this->segments)->underRoot($root);
    }

    public function __toString(): string
    {
        return implode(static::SEPARATOR, $this->segments);
    }
}
