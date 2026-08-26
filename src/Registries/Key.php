<?php

namespace Rushing\Popcorn\Registries;

use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;

/**
 * The canonical {@see RegistryKey}: dot-separated lowercase segments, `beam.realm.overlays`.
 *
 * ## It validates; it never repairs
 *
 * There is no `normalizeKey`, and that is the decision, not an omission (ticket 05). A key is legal
 * or it throws — no case folding, no separator unification, no trimming, no alias resolution. Four
 * consequences, each load-bearing:
 *
 * - **Idempotence is trivially true.** Nothing is rewritten, so the question "is this input already
 *   normalized?" — the question that killed systemd's `systemd-escape --mangle` — cannot be asked.
 * - **The runtimes cannot disagree.** The charset is ASCII-lowercase with no folding rule anywhere, so
 *   PHP `strtolower()` and JS `toLowerCase()` never diverge (the Turkish dotted/dotless `İ`/`ı` is
 *   the standing bug in every system that folds). A TS port is one regex and a `split('.')` — there
 *   is no mini-language to reproduce, because `..` / `...` / `..2` were all dropped.
 *
 * ## `-`, `_` and `:` are all legal INSIDE a segment; `.` alone separates
 *
 * Ticket 05 set the charset at `[a-z0-9-]` and pinned `beam_realm` and `beam:realm` as illegal. Ticket
 * 30 widened it, on the dev's call, after the narrow charset started rejecting live estate keys rather
 * than catching mistakes: `grounding.source.context_scope` — whose leaf is a key lifted from a customer
 * grounding schema — and `ParticleOperationRegistry`'s `resource:name`, which ticket 21 D9 had recorded
 * as the standing counter-example to "a rekey is a one-line attribute change".
 *
 * The widening admits CHARACTERS; it does not add a fold, so every property above survives intact. The
 * separator is still `.` and only `.`, so `:` is not an alternate dot — `beam:realm` is now ONE segment
 * spelled with a colon, not two segments. `/` stays rejected, because it means a composer coordinate
 * and a MIME type and has no business inside an address.
 *
 * The joiners are interchangeable to the GRAMMAR and never to a reader: dotted-kebab remains the house
 * spelling for anything newly written, and `_`/`:` exist so a domain that already spells its identity
 * that way — a customer's schema key, a type-tagged instance id — is not forced through a rename it
 * gains nothing from. Note the neighbouring `api-surface-coherence` map spent real effort REMOVING
 * snake keys from the app surface; this widening is not a licence to put them back.
 * - **Parsing is here, not on the registry.** A `parseKey()` on `Registry` is a method a registry can
 *   override, and then two registries disagree about what a key IS — which breaks the index the
 *   moment a key crosses a package boundary.
 * - **Uppercase is rejected, not folded**, so a class name is never accidentally a key. Deriving one
 *   from a class is deliberate and explicit: {@see fromClass()}.
 *
 * ## Reach is not lexical
 *
 * `resolve()` never walks up this key. `beam.realm.overlays.article` does not fall back to
 * `beam.realm.overlays` — the dotted key is a real tree for enumeration, routing and display, and
 * flat for resolution. Where an entry genuinely inherits, the registry declares the chain (the
 * shipped `RealmResourceRegistry::chain()` shape); it is never inferred from how someone spelled a
 * key. {@see isUnder()} and {@see parent()} serve enumeration and longest-prefix routing, which walk
 * SEGMENTS, never characters — `beam.realms` is not under `beam.realm`.
 */
class Key implements RegistryKey
{
    /**
     * One segment: lowercase alphanumeric groups joined by `-`, `_` or `:`. A joiner may never lead,
     * trail or double, so `_foo`, `foo:` and `a::b` are all still rejected — the widening is about
     * which characters may sit BETWEEN groups, not about admitting ragged input.
     *
     * `\z` rather than `$` on purpose — `$` would accept a trailing newline, which is a key that
     * prints identically to a legal one.
     *
     * ⚠️ **Do not port this pattern verbatim to JavaScript. The JS equivalent is `$`, not `\z`.**
     * JS has no `\z`; unflagged it is an identity escape for a literal `z`, so a copied pattern
     * rejects every legal key and accepts `beamz` — an inverted matcher with no syntax error to
     * catch it. JS `$` without the `m` flag means what PHP's `\z` means (PHP's `$` is the loose one,
     * which is why this is `\z` here and `$` there). The grammar itself ports fine; the *text* of the
     * pattern does not, and the conformance corpus at `tests/Fixtures/conformance/registry-key.json`
     * is what fails loudly the day someone copies it anyway (ticket 16).
     */
    public const SEGMENT_PATTERN = '/^[a-z0-9]+(?:[-_:][a-z0-9]+)*\z/';

    public const SEPARATOR = '.';

    /** @param  list<string>  $segments */
    private function __construct(private array $segments) {}

    /** @throws InvalidRegistryKey */
    public static function parse(string $key): self
    {
        return static::tryParse($key) ?? throw new InvalidRegistryKey(
            "`{$key}` is not a legal registry key: expected dot-separated lowercase segments, e.g. `beam.realm.overlays`. "
                .'Segments may join groups with `-`, `_` or `:`, but never lead, trail or double one, and `/` is not a key character.'
        );
    }

    public static function tryParse(string $key): ?self
    {
        if ($key === '') {
            return null;
        }

        $segments = explode(static::SEPARATOR, $key);

        foreach ($segments as $segment) {
            if (preg_match(static::SEGMENT_PATTERN, $segment) !== 1) {
                return null;
            }
        }

        return new self($segments);
    }

    /** Coerce the `RegistryKey|string` union every contract method accepts. Passthru for a key. */
    public static function of(RegistryKey|string $key): RegistryKey
    {
        return $key instanceof RegistryKey ? $key : static::parse($key);
    }

    /**
     * The explicit, opt-in derivation of a key from a class — the reason uppercase can be rejected
     * outright without the 42-descriptor rekey (ticket 21) tripping over `class_basename()`.
     * `Splicewire\Beam\Realm\RealmRegistry` becomes the single segment `realm-registry`; a package
     * that wants `beam.realm` says so deliberately, which is the point.
     */
    public static function fromClass(string $class): self
    {
        $position = strrpos($class, '\\');

        $short = $position === false ? $class : substr($class, $position + 1);

        $kebab = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $short);

        return static::parse(strtolower((string) $kebab));
    }

    /** @param  list<string>  $segments */
    public static function fromSegments(array $segments): self
    {
        return static::parse(implode(static::SEPARATOR, $segments));
    }

    /**
     * The zero-segment key: the root of the whole tree, rendering as the empty string.
     *
     * Deliberately NOT reachable through {@see parse()} — `parse('')` still throws, because an empty
     * string arriving from config or a request is a bug, not an address, and a parser that answered
     * "the root of everything" to it would be the loudest possible silent failure. This is the
     * explicit, opt-in constructor, exactly as {@see fromClass()} is for class-derived keys.
     *
     * It exists because {@see RegistryIndex} needs it: the index is the one registry whose keyspace is
     * the WHOLE keyspace rather than a branch of it, so its declared root is this. That is what lets
     * the index conform to its own contract instead of carrying an exemption from it — every other
     * registry stamps its root onto incoming keys, and stamping a zero-segment root is a no-op, so the
     * rule holds for the index without being special-cased for it (registry-kernel ticket 20).
     */
    public static function root(): self
    {
        return new self([]);
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

    /** The key one segment shallower, or null at the root. */
    public function parent(): ?self
    {
        if (count($this->segments) < 2) {
            return null;
        }

        return new self(array_slice($this->segments, 0, -1));
    }

    /**
     * Whether this key sits STRICTLY below `$other` — segment-wise, so `beam.realms` is not under
     * `beam.realm` however much the strings suggest otherwise. Longest-prefix routing composes this
     * with {@see equals()}; it is deliberately not folded in here, because "at or under" and "under"
     * are different questions and a caller should say which it means.
     */
    public function isUnder(RegistryKey|string $other): bool
    {
        $prefix = static::of($other)->segments();

        if (count($prefix) >= count($this->segments)) {
            return false;
        }

        return array_slice($this->segments, 0, count($prefix)) === $prefix;
    }

    public function __toString(): string
    {
        return implode(static::SEPARATOR, $this->segments);
    }
}
