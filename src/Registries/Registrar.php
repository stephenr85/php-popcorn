<?php

namespace Rushing\Popcorn\Registries;

/**
 * A reader that fills a registry from ONE source on its behalf.
 *
 * This is what replaced `ManifestSeam` (registry-kernel ticket 07 D1/D2). The enum tried to LABEL how a
 * registry got filled, one label per registry, and the label was hand-written beside the code rather
 * than derived from it — so three of its seven cases described things that were not registries or not
 * injection points, and the declared seam lied in three live registries. A list of attached objects
 * cannot lie the way a declared label can: a registry has zero or many registrars, and what they say
 * about themselves is a property of what they actually do.
 *
 * ## The source is the PLACE; the registrar is the READER
 *
 * Popcorn deliberately coins no `Source` TYPE. The estate already spends that word, and it spends it
 * consistently on the other side of this seam: across ~65 non-vendor `*Source` classes — `GraphSource`,
 * `OptionsSource`, `RouteManifestSource` — a Source is an *entry*, something registered INTO a registry,
 * and the two plurals settle it outright (`SchemaSources` and `GroundingSources` are registries whose
 * entries are Sources). Naming the filler `ConfigSource` would put one word on both sides of the seam.
 * `Registrar` already means exactly this across 7 consistent non-vendor precedents (ticket 07 D5).
 *
 * "Source" survives in its true position: the string {@see source()} returns.
 *
 * ## Two, not three
 *
 * Ticket 07 D2 named three registrars from reasoning — `ConfigRegistrar`, `AttributeRegistrar` and a
 * `ComposerRegistrar` reading `extra.*` fragments. Ticket 15 then cross-tabbed the census and found the
 * composer-fed shape has **zero live instances**, and ticket 24 re-checked it against source: the two
 * candidates both turn out to be something else. `LintStackRegistry` reads `extra.surgeon.lint` to
 * FILTER a constructor-seeded list at read time, not to fill it; `DeclaredContractSource` merges
 * `extra.package-topology` into an immutable `TopologyContract` builder, which has no keyspace and no
 * `register()`. So the completeness test is **two registrars, one contract, one resolve**.
 *
 * The interface is unchanged by that, and so is D6's inverted edge: if a composer-fed registry ever
 * appears, `rushing/php-package-topology` ships the implementation against this interface — the kernel
 * does not grow a required dependency to buy one file read. Nothing here is waiting on it. This is the
 * third enumeration on this map to be caught holding a member with no instances, after
 * `ManifestSeam::DeclaredComposer` and `ComposerRegistrar` itself: **check the instances before adding
 * a case.**
 *
 * ## Eager, at the owner's boot
 *
 * Registrars run when they are attached, and an owner attaches in its own `boot()` — before consumer
 * providers boot and hand-register. Explicit registration therefore lands second and wins by
 * {@see OnDuplicate::Supersede} alone, with no tier, no branch and no precedence rule (ticket 07 D9).
 * Lazy-on-first-read would invert exactly that and let config beat explicit registration.
 *
 * ## What a registrar writes is serialisable — where its projection returns data
 *
 * Both registrars read *declarative* sources — arrays and class-strings — so registrar output can be
 * cached ({@see Registrars\CachedRegistrar}) and, in principle, baked for a TS port. Hand-registration
 * carries no such guarantee: `SchemaSources`' own hint registers a closure. The line falls out of what
 * the sources ARE rather than being legislated (ticket 07 D12).
 *
 * **Ticket 07 D12 stated this more strongly than the code supports, and ticket 16 corrected it.** The
 * honest form: closures are confined to hand-registration **and to registrar CONFIGURATION**, and a
 * registrar's output is serialisable only where its projection returns data.
 * {@see Registrars\AttributeRegistrar} takes two callables — `$project`, which turns a scanned class
 * into an entry, and `$key` — so a host can project a scanned class into anything at all, including an
 * object closing over a closure. That is a deliberate seam, not a leak: refusing it would be the kernel
 * legislating what a host may register, which this map has declined to do five times over. But it means
 * "registrar output is serialisable by construction" is a property of the SOURCES, not a guarantee of
 * the interface, and anything baking registrar output for another runtime has to check the projection
 * rather than trust the shape. {@see Registrars\RegistrarCache} already states the same caveat from the
 * cache's end.
 */
interface Registrar
{
    /**
     * Read this registrar's source and write what it finds into `$registry`.
     *
     * **Every write must carry a meaningful `$by`** — the config key, the scanned class's own FQCN, the
     * composer fragment's package name. `null` is legal on {@see Registry::register()} and degrades two
     * things a registrar has no excuse to degrade: the miss diagnostics and the supersession record
     * (ticket 07 D13, 06 D11). A registrar is the one writer that always knows where its entry came
     * from, because reading from somewhere is its entire job.
     */
    public function fill(Registry $registry): void;

    /**
     * Where this registrar reads from, in a form a human recognises: `config beam.core.renderings`,
     * `#[ParticleResource] under app/Data`, `extra.beam.contracts`.
     *
     * Not decoration. It is what {@see RegistryIndex} renders in place of the deleted `seam` blurb and
     * the deleted `where` field — derived from the registrars a registry actually has, so it cannot
     * drift the way a hand-written hint beside them could, and more specific than `registerHint` ever
     * was (the generic blurb never named the config key). It is also the cache key
     * {@see Registrars\CachedRegistrar} uses (ticket 07 D4/D7).
     */
    public function source(): string;
}
