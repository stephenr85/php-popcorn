<?php

namespace Rushing\Popcorn\Registries\Registrars;

use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * A {@see Registry} that passes everything through to the real one and keeps a copy of the writes.
 *
 * {@see CachedRegistrar}'s recorder, and internal to it. A registrar's whole interface is
 * `fill(Registry $registry)`, so the only way to learn what one WROTE is to hand it something that
 * watches — there is no return value to inspect and no hook to install.
 *
 * It decorates rather than substitutes, and that is the load-bearing part: a registrar is entitled to
 * READ the registry it is filling (checking `has()` before writing is an obvious thing for one to do),
 * so a recorder that answered reads out of an empty stub would change what the registrar decides to
 * write. Everything is forwarded; only `register()` is also remembered.
 *
 * The key is recorded exactly as the registrar passed it — relative, unstamped. Replaying it through
 * `register()` on a real store sends it through the same door and gets the same stamping, so a cached
 * fill and a live one are indistinguishable at the far end (ticket 20 D2).
 *
 * @template TEntry
 *
 * @implements Registry<TEntry>
 */
class RecordingRegistry implements Registry
{
    /** @var list<array{key: RegistryKey|string, entry: TEntry, by: string|null, ability: string|null}> */
    private array $writes = [];

    /** @param  Registry<TEntry>  $inner */
    public function __construct(private Registry $inner) {}

    /** @return list<array{key: RegistryKey|string, entry: TEntry, by: string|null, ability: string|null}> */
    public function writes(): array
    {
        return $this->writes;
    }

    /** @param  TEntry  $entry */
    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $this->writes[] = ['key' => $key, 'entry' => $entry, 'by' => $by, 'ability' => $ability];

        $this->inner->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->inner->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->inner->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->inner->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->inner->matches($key);
    }

    public function keys(): array
    {
        return $this->inner->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->inner->unfiltered();
    }
}
