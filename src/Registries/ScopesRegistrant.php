<?php

namespace Rushing\Popcorn\Registries;

/**
 * Wraps a provider's whole `register()` and `boot()` in {@see Registrant::scoped()}, so every registry
 * write in them that names no `$by` records the package.
 *
 * For a provider whose lifecycle lives in its parent, `spatie/laravel-package-tools`'
 * `PackageServiceProvider` among them: the parent's `register()`/`boot()` call the provider's
 * `packageRegistered()`/`packageBooted()` hooks, and the trait brackets both. A provider that defines
 * its own `register()` or `boot()` overrides the trait's, so it wraps that method's body with
 * `Registrant::scoped()` directly instead.
 *
 * ```php
 * class BeamServiceProvider extends PackageServiceProvider
 * {
 *     use ScopesRegistrant;
 *
 *     protected function registrant(): string
 *     {
 *         return 'splicewire/laravel-beam';
 *     }
 * }
 * ```
 *
 * Framework-free on purpose: it names no Laravel type, only `parent::`, so it lives beside
 * {@see Registrant} and every package that reaches the kernel can use it. The registrant is stated,
 * not inferred: no stack walk, no composer lookup (registry-kernel tickets 01 and 06). A closure the
 * provider defers, such as a singleton factory or an `afterResolving` hook, runs outside the scope.
 */
trait ScopesRegistrant
{
    /** The composer package name this provider registers as. */
    abstract protected function registrant(): string;

    /**
     * Laravel ignores what `register()` and `boot()` return, so the parent's `$this` is not passed on.
     * Untyped natively, like the parents, so a subclass's untyped override stays compatible.
     *
     * @return void
     */
    public function register()
    {
        Registrant::scoped($this->registrant(), function (): void {
            parent::register();
        });
    }

    /** @return void */
    public function boot()
    {
        Registrant::scoped($this->registrant(), function (): void {
            parent::boot();
        });
    }
}
