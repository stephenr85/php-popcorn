<?php

namespace Rushing\Popcorn\Registries;

/**
 * A class name as a registry key: the FULL namespace, kebab-cased into one segment per part.
 *
 * ## Why this exists beside `Key::fromClass()`
 *
 * {@see Key::fromClass()} already makes class-as-key kernel vocabulary, and it is the right tool
 * where a short, human-written key is wanted — its docblock says so: `Splicewire\Beam\Realm\
 * RealmRegistry` becomes the single segment `realm-registry`, and "a package that wants `beam.realm`
 * says so deliberately, which is the point."
 *
 * That basename reduction is lossy, and the loss is not theoretical. Measured across the splicewire
 * estate on 2026-08-27: **487 distinct `*Data` class basenames, 17 of which name more than one class**
 * (34 classes — `SyncData`, `ThreadData`, `ThreadMessageData`, `UserData`, `PlanData` among them).
 * Under {@see OnDuplicate::Supersede} two such classes collide **silently**: the second registrant
 * wins, and {@see RecordsSupersession::superseded()} records it as a legitimate override rather than
 * an accident, because at that point nothing can tell the two apart. Carrying the namespace makes the
 * collision unrepresentable rather than merely unlikely.
 *
 * So the two are not competitors. Reach for `fromClass()` when a registry's keyspace is small and
 * curated and the short name IS the identity; reach for this when the population is open-ended —
 * every Data class in an estate, every handler a host might declare — and a basename is a guess.
 *
 * ## A constructor, never a parser
 *
 * {@see Key} refuses folding on purpose — "no case folding, no separator unification, no trimming,
 * no alias resolution" — so the runtimes cannot disagree and a TS port stays one regex and a
 * `split('.')`. This class does not weaken that: it CONSTRUCTS a key from a class name, exactly as
 * {@see Key::fromClass()} and {@see Key::root()} are explicit opt-in constructors, and every segment
 * it produces must still satisfy {@see Key::SEGMENT_PATTERN} or it throws. What it must never become
 * is a lenient `parse()` — that would be ambient, invocable by any string-holder, and the same input
 * would address different entries depending on which door it came through.
 *
 * ## Not `Rootable`, deliberately
 *
 * {@see Rootable} lets the kernel re-address a key under a registry's declared root. A class key is
 * already absolute — the namespace IS the address — and stamping `schemas.fixtures` onto
 * `splicewire.beam.commerce.data.plan-edit-data` produces a seven-segment key whose first two
 * segments carry no information the third through seventh do not. {@see AbsoluteUriKey} reaches the
 * same conclusion from the other direction: it implements `Rootable` and then declines. This declines
 * by not implementing it, which is the same decision with less ceremony, and it is why a consumer
 * wanting a short rooted name should key by that name and keep this as the fallback for shapes that
 * have none.
 */
class ClassKey implements RegistryKey
{
    /** @param  list<string>  $segments */
    private function __construct(private array $segments) {}

    public static function of(string $class): self
    {
        $segments = [];

        foreach (explode('\\', trim($class, '\\')) as $part) {
            $segment = strtolower((string) preg_replace(
                '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
                '-',
                $part,
            ));

            // Widen WHAT can be addressed, never the charset a segment may contain. A name that
            // cannot yield a legal segment is refused rather than folded into one — folding is the
            // thing `Key` exists to not do.
            if (preg_match(Key::SEGMENT_PATTERN, $segment) !== 1) {
                throw new \InvalidArgumentException(
                    "[{$class}] yields the illegal key segment [{$segment}]."
                );
            }

            $segments[] = $segment;
        }

        // `new self`, not `new static`: this type is not designed for extension, so declaring
        // `@phpstan-consistent-constructor` as {@see AbsoluteUriKey} does would assert a contract
        // nothing here intends to keep. That sibling earns the tag — it has one deliberate subclass.
        return new self($segments);
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

    public function __toString(): string
    {
        return implode(Key::SEPARATOR, $this->segments);
    }
}
