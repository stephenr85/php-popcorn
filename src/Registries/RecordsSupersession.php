<?php

namespace Rushing\Popcorn\Registries;

/**
 * A registry that can be asked what it overwrote.
 *
 * The recording is **always on** wherever {@see OnDuplicate::Supersede} is declared — this interface
 * exposes the record; it does not switch it on. Under `Reject` nothing is ever displaced, and under
 * `Admit` both entries stay live, so on those two the answer is legitimately always empty
 * (registry-kernel ticket 01 D9).
 */
interface RecordsSupersession
{
    /**
     * What was displaced at `$key`, oldest first.
     *
     * @return list<Superseded>
     */
    public function superseded(RegistryKey|string $key): array;
}
