<?php

namespace Rushing\Popcorn\Registries;

/**
 * One displaced entry: what was at a key before someone wrote over it.
 *
 * Recorded whenever {@see OnDuplicate::Supersede} overwrites, and **never resolved** — a superseded
 * entry is history, and history must not participate in a read. Otherwise "several entries under one
 * key" and "one entry overridden twice" become the same thing, and every `RunAll` registry starts
 * running dead entries (registry-kernel ticket 01 D9).
 *
 * ## Why it carries a registrant and a sequence, and nothing else
 *
 * The two questions a supersession record has to answer are *who put the thing that lost here* and *in
 * what order did this happen*. `$by` and `$sequence` answer both. There is deliberately no
 * `debug_backtrace()`: a stack is expensive, unstable across PHP versions, and answers a question
 * ("through what call path") nobody asked.
 *
 * The always-on record is also, for free, how duplicate ROOTS are detected — two packages declaring the
 * same root supersede each other in the index and the audit reports it, with no bespoke scan (ticket
 * 05, ticket 20).
 *
 * Cleared with the entry by {@see Forgettable::forget()} — see its docblock for why keeping it would be
 * the leak the teardown exists to prevent.
 *
 * ## The key is the KEY, not its rendering
 *
 * `$key` is a {@see RegistryKey} rather than the string it prints as, and the distinction is not
 * pedantry: `__toString()` is the owner's presentation and is never identity (ticket 05 as amended by
 * ticket 11). A foreign key type — `NamespaceUriKey`, whose display form is a URI — cannot be
 * reconstructed from its rendering, because the kernel has no parser for it and never will. Holding the
 * string would have made supersession history lossy for exactly the registries whose keys are hardest
 * to rebuild, and lossy *silently*, since `Key`'s own rendering round-trips and every test used one
 * (ticket 16; the same trap ticket 11 found in `BasicRegistry` and fixed in `2e94318`).
 */
class Superseded
{
    /**
     * @param  RegistryKey  $key  the key that was written over — the key object, never its rendering
     * @param  mixed  $entry  the displaced entry itself
     * @param  string|null  $by  who registered the DISPLACED entry; null where the write named nobody
     * @param  int  $sequence  monotonic per registry, oldest first — the answer to "in what order"
     */
    public function __construct(
        public RegistryKey $key,
        public mixed $entry,
        public ?string $by,
        public int $sequence,
    ) {}
}
