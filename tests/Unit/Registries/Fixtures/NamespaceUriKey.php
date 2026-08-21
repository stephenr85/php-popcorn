<?php

namespace Rushing\Popcorn\Tests\Unit\Registries\Fixtures;

use Rushing\Popcorn\Registries\RegistryKey;

/**
 * A FOREIGN {@see RegistryKey} — a consumer's own key type, standing in for
 * `schemastud/laravel-json-ns`, which keys the registry by namespace URI because URI-is-identity is
 * that package's whole thesis (a CURIE and an `@namespaced` object resolving to the same URI must hit
 * the same registration).
 *
 * It is the shape ticket 05's amendment sanctions and ticket 11 found broken: opaque segments, an
 * owner-defined rendering that {@see \Rushing\Popcorn\Registries\Key::parse()} would reject outright,
 * and a version pin that is structurally a CHILD of its version-free stem.
 */
class NamespaceUriKey implements RegistryKey
{
    /** @param  list<string>  $segments */
    private function __construct(private array $segments, private string $uri) {}

    /** `https://schemastud.dev/ns/grounding/2` → the stem, then the pin as its child. */
    public static function of(string $uri): self
    {
        if (preg_match('#^(.*)/(\d+)$#', $uri, $matched) === 1) {
            return new self([$matched[1], $matched[2]], $uri);
        }

        return new self([$uri], $uri);
    }

    public function segments(): array
    {
        return $this->segments;
    }

    public function equals(RegistryKey $other): bool
    {
        return $this->segments === $other->segments();
    }

    public function __toString(): string
    {
        return $this->uri;
    }
}
