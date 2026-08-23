<?php

namespace Rushing\Popcorn\Registries\Registrars;

/**
 * A {@see RegistrarCache} that lives for one process and then forgets.
 *
 * The default, and it is not a placeholder for a "real" one. It buys the case that actually exists in a
 * single boot: the same expensive scan feeding more than one registry, or a test suite attaching the
 * same registrar to a fresh store per case. It cannot go stale, which is why it can ship while the
 * invalidation question is still unowned.
 *
 * A cross-process cache is a different artifact with a different lifecycle — disposable, per-environment,
 * and wanting a host's `optimize`/`optimize:clear` hooks — and belongs on the Laravel side where those
 * hooks exist.
 */
class ArrayRegistrarCache implements RegistrarCache
{
    /** @var array<string, list<array{key: mixed, entry: mixed, by: string|null, ability: string|null}>> */
    private array $writes = [];

    public function get(string $source): ?array
    {
        return $this->writes[$source] ?? null;
    }

    public function put(string $source, array $writes): void
    {
        $this->writes[$source] = $writes;
    }
}
