<?php

namespace Rushing\Popcorn\Registries;

use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;

/**
 * An absolute URI as a registry key: **one opaque segment**, the whole URI, compared whole.
 *
 * ## Why the URI is not decomposed
 *
 * `https://app.splicewire.com/json-schemas/grounding` cannot be segmented into the dotted keyspace
 * without lying. Its authority contains dots, so as one segment it is illegal and as three (`app`,
 * `splicewire`, `com`) it is indistinguishable from an ordinary dotted address — two URIs on two hosts
 * could collide with each other and with real keys (registry-kernel ticket 58 D2). So the URI is kept
 * whole and identity is the whole string. Anything relative has its own type, {@see RelativeUriKey},
 * which really does decompose because it really can.
 *
 * ## It is {@see Rootable} and it DECLINES
 *
 * This is a kernel-owned type, so after ticket 64's widening the door could stamp it — and stamping a
 * dotted root onto one opaque URI segment produces a two-segment key whose first half addresses a
 * branch and whose second half is a URI, which is not an address anyone can resolve or render. So
 * {@see underRoot()} returns `$this`.
 *
 * That is a **decision, not an incapacity**, and the difference is why this implements the interface
 * rather than omitting it: a reader can see the kernel considered stamping here and refused, instead of
 * inferring it from a missing `implements`. The practical consequence is the one ticket 20 D3 named and
 * ticket 64 narrowed — entries keyed this way are not addressable through the global keyspace,
 * reachable as a registry through the index, never through `pop()`.
 *
 * ## The one subclass, and what it may override
 *
 * `Schemastud\JsonNs\NamespaceUriKey` extends this to express the pinned-version-as-child rule
 * (`isPinned() ? [stem, version] : [uri]`), which is json-ns-specific and cannot be generic — that
 * package's dispatcher resolves a pinned URI then falls back to its stem, and expressing the pin as a
 * structural CHILD is what makes that ordinary tree structure rather than string surgery. The
 * dependency edge is legal and already exists: `schemastud/php-json-ns` requires `rushing/php-popcorn`.
 *
 * Subclasses build their own segments through the constructor. What they may NOT do is start stamping:
 * the declining above is the shared contract, and `json-ns` wants it — URI-is-identity is that package's
 * thesis, and relative-forever is the trade it takes deliberately.
 *
 * @phpstan-consistent-constructor `of()` is `new static`, so a subclass inherits the parse-and-refuse
 * gate instead of silently returning a base instance. The one subclass overrides `of()` outright and
 * keeps the constructor signature verbatim, which is what this annotation asserts.
 */
class AbsoluteUriKey implements Rootable
{
    /**
     * Any RFC 3986 scheme followed by `:`. Matched to REQUIRE — the mirror of
     * {@see RelativeUriKey}'s refusal, so the two types partition their input rather than overlapping
     * and leaving a caller unsure which it got.
     */
    private const ABSOLUTE_PATTERN = '~^[a-zA-Z][a-zA-Z0-9+.\-]*:~';

    /**
     * @param  list<string>  $segments  what this key COMPARES as — one opaque segment here; a subclass
     *                                  may say otherwise, and json-ns does.
     * @param  string  $uri  what it RENDERS as. Never the identity; see {@see equals()}.
     */
    protected function __construct(protected array $segments, protected string $uri) {}

    /** @throws InvalidRegistryKey */
    public static function of(string $uri): static
    {
        if ($uri === '' || preg_match(self::ABSOLUTE_PATTERN, $uri) !== 1) {
            throw new InvalidRegistryKey(
                "`{$uri}` is not an absolute URI, so it cannot be an AbsoluteUriKey: expected a scheme, "
                    .'e.g. `https://app.splicewire.com/json-schemas/grounding`. A relative path is '
                    .'RelativeUriKey\'s, and a dotted address is Key\'s.'
            );
        }

        return new static([$uri], $uri);
    }

    /** @return list<string> */
    public function segments(): array
    {
        return $this->segments;
    }

    /**
     * Equality is defined on SEGMENTS, never on the source string — the property that makes two
     * spellings of one identity the same key and two keyspaces that happen to render alike distinct.
     */
    public function equals(RegistryKey $other): bool
    {
        return $this->segments === $other->segments();
    }

    /**
     * Declines, deliberately — see the class docblock. Returning `$this` rather than throwing is what
     * keeps the door uniform: every {@see Rootable} answers the same question, and "no change" is a
     * legal answer to it.
     */
    public function underRoot(Key $root): RegistryKey
    {
        return $this;
    }

    /** The URI itself. A rendering for humans and transports; never the identity. */
    public function __toString(): string
    {
        return $this->uri;
    }
}
