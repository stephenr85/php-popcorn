# rushing/php-popcorn

**Framework-free core for transport-agnostic invocable capabilities and self-validating strategy
ladders** — the invocable kernel behind `rushing/laravel-popcorn` (and its `laravel-popcorn-bubble`
/ `laravel-popcorn-wasm` siblings).

```bash
composer require rushing/php-popcorn
```

No Laravel by design: the only dependency is `symfony/finder`, and `composer.json` carries a
`mustNotRequire: illuminate/*` constraint. If you want container wiring, config, caching and the
`popcorn:registries:cache` bake step, you want **`rushing/laravel-popcorn`** — this package is the
kernel it stands on.

Under `src/`:

- `InvocableRegistry` + `Binding` — the registry of named invocable capabilities;
- `Invocables/`, `Contracts/`, `Concerns/` — what a capability is and how one is declared;
- `Ladders/` — self-validating strategy ladders (ordered strategies, first honest winner);
- `Discovery/` — filesystem discovery of declarations (symfony/finder);
- `Registries/` — the registry primitives the Laravel sibling bakes.

Start with [`AGENTS.md`](AGENTS.md); the contract docblocks are the reference.
Tests: `composer test`.
