<?php

namespace Rushing\Popcorn\Registries\Registrars;

use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;

/**
 * Wraps any registrar and replays its writes instead of re-reading its source.
 *
 * ## A decorator, not a per-registrar property
 *
 * The alternative was a `cached: true` flag on each registrar, and it was refused because the costs are
 * not comparable (registry-kernel ticket 07 D7). A {@see ConfigRegistrar}'s read is an array traversal —
 * free, and a cache around it is pure overhead. An {@see AttributeRegistrar}'s read walks every `*.php`
 * under its scan paths and `class_exists`es each one, **uncached, on every boot**, because
 * `AttributedClassScanner` has no cache of any kind. So one registrar is wrapped by default and the
 * other is not, and the decision belongs to whoever wires them rather than to the registrar itself.
 *
 * ## The cache key is `source()`, which is why `source()` had to be honest
 *
 * `#[ParticleResource] under app/Data` identifies the read completely: the attribute and the paths are
 * the whole of the registrar's input. That is the same string the index renders as "how do I contribute
 * to this?", which is a coincidence worth stating rather than relying on — a registrar whose
 * `source()` did not distinguish two different reads would silently serve one's entries for the other.
 *
 * ## Staleness is not handled here, and the miss is louder than it looks
 *
 * There is no invalidation, and there is not going to be one here. Ticket 07 D7 handed staleness to
 * ticket 12, ticket 12 dissolved without taking it, and registry-kernel ticket 39 finally answered it:
 * **the cross-process discovery cache is not a kernel concern, because the estate already has one.**
 * `Splicewire\Beam\Frame\FrameResourceManifest` writes an opcache-friendly class-string manifest to
 * `bootstrap/cache/` and is wired through `ServiceProvider::optimizes()` — the Laravel-side home ticket
 * 24 D5 predicted, built before the question was asked. So this decorator stays in-process on purpose.
 * The shipped default is {@see ArrayRegistrarCache}, which lives one boot and therefore cannot go stale.
 *
 * If you are here because you want a persistent one: 39 refused it on the two-beneficiary bar, both
 * beneficiaries being inside `splicewire/laravel-beam`, and every package requiring `rushing/php-popcorn`
 * is a Laravel package — so there is no framework-free host for a kernel-side file cache to serve.
 *
 * ## What it does NOT wrap
 *
 * The registrar's writes, not its effects. If a registrar did something besides call `register()` — a
 * side effect, a log line, a container binding — a cache hit skips it. Both shipped registrars are pure
 * reads, and D12's "registrar output is serialisable by construction" is the property that makes this
 * safe; a registrar that breaks it should not be wrapped.
 */
class CachedRegistrar implements Registrar
{
    public function __construct(
        private Registrar $registrar,
        private RegistrarCache $cache,
    ) {}

    /** The default wiring: cache an attribute scan in memory for the life of the process. */
    public static function inMemory(Registrar $registrar): self
    {
        return new self($registrar, new ArrayRegistrarCache);
    }

    public function fill(Registry $registry): void
    {
        $cached = $this->cache->get($this->source());

        if ($cached !== null) {
            foreach ($cached as $write) {
                $registry->register($write['key'], $write['entry'], $write['by'], $write['ability']);
            }

            return;
        }

        // Recorded through a pass-through decorator rather than into a scratch store, so the wrapped
        // registrar sees the registry it is actually filling — reads included — and the entries land
        // once, on the first fill, rather than being written twice.
        $recorder = new RecordingRegistry($registry);

        $this->registrar->fill($recorder);

        $this->cache->put($this->source(), $recorder->writes());
    }

    /**
     * The wrapped registrar's own source, unchanged and unannotated.
     *
     * Caching is a property of THIS host's wiring, not of where the entries come from, and the index
     * renders this string to tell a reader how to contribute. `config beam.core.renderings (cached)`
     * would answer a question nobody asked with information that helps nobody contribute.
     */
    public function source(): string
    {
        return $this->registrar->source();
    }

    /** The registrar underneath, for tooling that needs to see through the decorator. */
    public function registrar(): Registrar
    {
        return $this->registrar;
    }
}
