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
 */
class Superseded
{
    /**
     * @param  string  $key  the key that was written over
     * @param  mixed  $entry  the displaced entry itself
     * @param  string|null  $by  who registered the DISPLACED entry; null where the write named nobody
     * @param  int  $sequence  monotonic per registry, oldest first — the answer to "in what order"
     */
    public function __construct(
        public string $key,
        public mixed $entry,
        public ?string $by,
        public int $sequence,
    ) {}
}
