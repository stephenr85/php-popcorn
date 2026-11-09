<?php

namespace Rushing\Popcorn\Registries;

use InvalidArgumentException;

/**
 * Who put an entry in a registry: the vocabulary of `$by`, and the scoped default for writes that name
 * nobody.
 *
 * ## The vocabulary: three forms
 *
 * | form | when it is the honest answer | example |
 * |---|---|---|
 * | `vendor/name` | a package's own code wrote the entry: its provider, or a built-in it seeds | `splicewire/laravel-beam` |
 * | `config:<key>` | the entry was read out of config, so the place is the config key | `config:beam.core.renderings` |
 * | `class:<FQCN>` | a class declared itself, e.g. an attribute scan found it | `class:App\Data\InvoiceData` |
 *
 * {@see self::fromConfig()} and {@see self::fromClass()} build the last two, so nobody spells them by hand.
 *
 * **An explicit `$by` is authoritative and is never rewritten or parsed.** In particular, a teardown
 * selector is not one of the three forms and does not need to be: `splicewire/tower` registers each
 * conduit projection by `<hydrator FQCN>:<tenant>:<publisher>:<conduit>` and tears the tenant down with
 * `forgetBy()` on that exact string. `forgetBy()` and `keysBy()` match byte for byte, and a teardown
 * whose string no longer matches is a silent no-op, which for a per-tenant selector means a
 * cross-tenant leak. So the vocabulary governs what new writes SAY. It never re-spells a string that
 * something may already select on, and bare FQCN registrants written before `class:` existed stay
 * legal. Readers render `$by` and do not classify it.
 *
 * ## The scoped default
 *
 * Requiring `$by` at every call site across the estate is unaffordable, and inferring it from the
 * call stack with `debug_backtrace()` is banned (registry-kernel tickets 01 and 06). So a provider
 * states its registrant once, around its registration block:
 *
 * ```php
 * Registrant::scoped('splicewire/laravel-beam', function (): void {
 *     // every register() in here whose $by is null records 'splicewire/laravel-beam'
 * });
 * ```
 *
 * A provider says it once for its whole lifecycle with {@see ScopesRegistrant}; Laravel code can also
 * open a scope through `Popcorn::registeredBy()` in `rushing/laravel-popcorn`.
 *
 * - **Null only.** {@see BasicRegistry::register()} applies the scope as `$by ??= Registrant::current()`,
 *   so an explicit registrant always wins, the tenant selector above included.
 * - **Dynamic extent, not lexical.** The scope covers what runs inside the callback, and it is popped
 *   in a `finally`. A closure the block DEFERS (a singleton factory, an `afterResolving` hook) runs
 *   later, outside it, and records `null` unless it is itself wrapped. That is deliberate: a scope
 *   that leaked past its block would attribute a later tenant switch, or another package's
 *   registrations, to whoever booted last.
 * - **Nested scopes stack.** The innermost wins, and leaving it restores the outer one.
 * - **The scope's registrant must be one of the three forms.** It is a default applied to entries
 *   nobody named, so it has to be the vocabulary. A free-form or per-tenant selector belongs in an
 *   explicit `$by`, where its author owns its teardown.
 *
 * @api
 */
class Registrant
{
    /** @var list<string> */
    private static array $scopes = [];

    /**
     * Run `$callback` with `$registrant` as the default for every write that names nobody.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     *
     * @throws InvalidArgumentException where `$registrant` is not one of the three forms
     */
    public static function scoped(string $registrant, callable $callback): mixed
    {
        if (! self::isVocabulary($registrant)) {
            throw new InvalidArgumentException(
                "A registrant scope must be a composer package name, 'config:<key>' or 'class:<FQCN>'; got '{$registrant}'. "
                .'A free-form or per-tenant registrant is an explicit $by, not a scope.',
            );
        }

        self::$scopes[] = $registrant;

        try {
            return $callback();
        } finally {
            array_pop(self::$scopes);
        }
    }

    /** The innermost open scope's registrant, or `null` outside every scope. */
    public static function current(): ?string
    {
        return self::$scopes === [] ? null : self::$scopes[array_key_last(self::$scopes)];
    }

    /** `config:<key>`: the entry was read out of config at `$key`. */
    public static function fromConfig(string $key): string
    {
        return 'config:'.$key;
    }

    /**
     * `class:<FQCN>`: the class declared itself into the registry.
     *
     * @param  class-string|string  $class
     */
    public static function fromClass(string $class): string
    {
        return 'class:'.ltrim($class, '\\');
    }

    /** Whether `$registrant` is one of the three forms. Used to guard scopes, never to reject a `$by`. */
    public static function isVocabulary(string $registrant): bool
    {
        if (preg_match('#^config:[^\s]+$#', $registrant) === 1) {
            return true;
        }

        if (preg_match('#^class:[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$#', $registrant) === 1) {
            return true;
        }

        // Composer's own package-name pattern.
        return preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $registrant) === 1;
    }
}
