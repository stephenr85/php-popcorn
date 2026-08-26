<?php

namespace Rushing\Popcorn\Registries;

/**
 * A {@see RegistryKey} the kernel can re-address under a registry's declared root.
 *
 * ## Why this is an interface and not a class list
 *
 * {@see BasicRegistry::door()} used to ask `instanceof Key` — the concrete canonical class — and its
 * docblock justified that with *"the kernel cannot construct a consumer's key type."* Those are two
 * different rules, and the estate had been paying for the gap: the check encoded **canonical** where
 * the intent was **constructible**, so `RelativeUriKey` and `AbsoluteUriKey` — types the kernel itself
 * ships — would have been treated as a stranger's. Registry-kernel ticket 58 D3 measured the whole
 * consequence chain that made archetype **f** expensive (never root-stamped → `matches(root)` returns
 * nothing → every enumeration rebuilds onto `keys()` → `ExistsInRegistry` hard-refuses) and found it to
 * be an artifact of that one `instanceof`, not a property of non-dotted keys.
 *
 * The replacement had to be *a property of the key type* rather than a list in the door, because a list
 * is a thing the next kernel key type forgets to join and nothing reports (ticket 64). So the key
 * carries the rule: implementing this is **the opt-in**, and a consumer's own `RegistryKey` stays
 * relative-forever by simply not implementing it — which is the same line ticket 20 D3 drew, arrived at
 * from the other end and now narrowed to the types that genuinely cannot be addressed.
 *
 * ## Implementing it does not oblige you to stamp
 *
 * {@see AbsoluteUriKey} implements this and **declines** — it returns itself — because stamping a
 * dotted root onto one opaque URI segment means nothing. Declining deliberately, in a type that could,
 * is not the same as being unable to, and the difference is legible here rather than inferred from an
 * absent interface.
 */
interface Rootable extends RegistryKey
{
    /**
     * This key as it should be STORED by a registry declaring `$root`.
     *
     * Must be idempotent: a key already at or under `$root` comes back unchanged, so a value that
     * passes through the door twice — every read of something that was written — is stable. A
     * zero-segment root ({@see Key::root()}, which only {@see RegistryIndex} declares) is a no-op for
     * the same reason, with no special case anywhere.
     *
     * The return is a `RegistryKey` rather than `static` on purpose: a type whose job is to PARSE a
     * foreign spelling ({@see RelativeUriKey}) hands back the canonical address it parsed to, because
     * the wire keeps its slashes and the kernel stores an address.
     */
    public function underRoot(Key $root): RegistryKey;
}
