<?php

namespace Rushing\Popcorn\Registries\Exceptions;

use RuntimeException;

/**
 * A membership read reached an index whose baked list is **absent** — so it does not know what is
 * described, and it will not pretend the answer is "nothing".
 *
 * ## Why this is a throw when almost nothing else in this estate is
 *
 * Registry-kernel ticket 73 collapsed declaring and describing into one act: `#[IsRegistry]` on the
 * class, a `root → class-string` list baked at build time, and lazy resolution on first read. Once the
 * hand-written `describe()` calls are gone there is **no fallback path**, so a host whose artifact never
 * got written would otherwise have an empty index and **no error at all** — every `routeTo()` returning
 * null, `popcorn:registries` showing nothing, and the {@see \Rushing\Popcorn\Registries\Gated} authorizer
 * never installed on anything. That is this estate's signature defect at maximum blast radius,
 * self-inflicted by the very change meant to remove it. **Absent must be loud, never empty** (73 D3.2).
 *
 * It does **not** contradict the estate's rule that *a check whose answer depends on the host must not
 * throw*. That rule governs facts which legitimately differ per host — is this package installed, is
 * this resource registered. **Whether the build step ran is not one of them:** it is identical at every
 * host that completed its install, there is exactly one correct answer, and it is never a legitimate
 * state in which to serve a request. Ticket 73 §1 went the other way, for the opposite reason — whether
 * two described registries overlap genuinely does depend on which providers a host loaded.
 *
 * ## It is raised at the DOOR, not at boot, and that is load-bearing
 *
 * The command that writes the artifact is an artisan command, and artisan boots the application. An
 * index that refused to boot while unbaked could therefore never be baked — a real bootstrap cycle, not
 * a hypothesis. So booting unbaked is allowed and **reading** is not.
 *
 * ## Present-but-EMPTY is not this
 *
 * An artifact that exists and lists nothing is a host that genuinely declares no registries, and it is
 * quiet. Only an artifact that is **missing** raises this. Collapsing the two into one empty array is
 * the distinction `Rushing\Doctor\Finding::inconclusive()` exists to draw, one tier down.
 */
class UnbakedRegistryIndex extends RuntimeException
{
    public function __construct(public string $reason)
    {
        parent::__construct($reason);
    }

    /**
     * @param  string  $path  where the artifact was expected
     * @param  string  $command  what writes it
     */
    public static function at(string $path, string $command): self
    {
        return new self(sprintf(
            'The registry index has no baked membership list, so it cannot say what is described and '
                .'will not answer "nothing". Expected `%s`; write it with `%s`. Until then every '
                .'membership read raises this instead of silently reporting an empty estate — a missing '
                .'artifact and a host that genuinely declares no registries are different states '
                .'(registry-kernel 73 D3.2).',
            $path,
            $command,
        ));
    }
}
