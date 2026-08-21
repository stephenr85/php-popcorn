<?php

namespace Rushing\Popcorn\Registries\Exceptions;

use RuntimeException;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * A read named a key and did not get one entry back. Thrown by `Registry::resolve()`;
 * `tryResolve()` returns null instead — the two are the whole miss design, and there is no runtime
 * miss-policy flag on top of them (registry-kernel ticket 06).
 *
 * ## It carries diagnostics, because that is only cheap now
 *
 * A miss knows the key, every candidate it considered, and **who registered each candidate**.
 * Provenance is the load-bearing part and it pays three times over: it is what makes last-wins
 * auditable, what makes this message useful, and — per the prior-art survey — the property whose
 * absence is the standing indictment of the Windows Registry, where no key records what put it
 * there. `LensRegistry` already does this by hand, naming the owning package in its collision
 * message; this generalises it.
 *
 * Spring is the model for the shape: `NoUniqueBeanDefinitionException extends
 * NoSuchBeanDefinitionException` and exposes the candidates found, rather than flattening ambiguity
 * into "not found". {@see AmbiguousRegistryMatch} is that subclass here.
 */
class RegistryMiss extends RuntimeException
{
    /**
     * @param  list<array{key: string, by: string|null}>  $candidates  every entry considered, each
     *                                                                 with the registrant that wrote it
     */
    protected function __construct(
        public string $key,
        public MissReason $reason,
        public array $candidates,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function absent(RegistryKey|string $key): self
    {
        $key = (string) $key;

        return new self($key, MissReason::Absent, [], "No entry registered under `{$key}`.");
    }

    /**
     * @param  list<array{key: string, by: string|null}>  $candidates
     */
    public static function ambiguous(RegistryKey|string $key, array $candidates): AmbiguousRegistryMatch
    {
        $key = (string) $key;

        return new AmbiguousRegistryMatch(
            $key,
            MissReason::Ambiguous,
            $candidates,
            "`{$key}` is ambiguous — ".count($candidates).' candidates and no single answer: '
                .implode(', ', array_map(
                    fn (array $c) => "`{$c['key']}`".($c['by'] === null ? '' : " (by {$c['by']})"),
                    $candidates,
                )).'. Name one exactly, or declare a disambiguation the registry can apply.',
        );
    }

    public static function unpopulated(RegistryKey|string $key, string $root): self
    {
        return new self(
            (string) $key,
            MissReason::Unpopulated,
            [],
            "The `{$root}` registry declares itself Required and is empty — nothing has registered "
                .'into it. This is usually a service provider that did not boot, not a bad key.',
        );
    }

    /**
     * A visibility predicate hid the entry. **Renders as {@see absent()} on purpose** — telling the
     * caller the key exists is the leak this exists to avoid. Only {@see $reason} distinguishes it,
     * for the doctor and the log.
     */
    public static function filtered(RegistryKey|string $key): self
    {
        $key = (string) $key;

        return new self($key, MissReason::Filtered, [], "No entry registered under `{$key}`.");
    }
}
