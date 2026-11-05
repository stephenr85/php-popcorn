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

## Registries

This package declares one registry:

- [`popcorn.invocables`](docs/registries/popcorn.invocables.mdx): named, transport-agnostic
  capabilities, array in and array out. `InvocableRegistry` declares it.

`RegistryIndex` also declares the empty root: the index of every registry, keyed by the root each
one owns. It has no page of its own. The check that a package documents its registries,
`Registries\Docs\RegistryDocsCheck`, lives here too. The
[`rushing/laravel-popcorn` README](https://github.com/stephenr85/laravel-popcorn#documenting-your-registries)
explains how to adopt it.

`Registries\Docs\DataExamples` keeps the **data examples** on a registry page honest. An example is a
PHP file, `docs/registries/examples/<page>/<name>.php`, that returns a value: a registry's resolved
entries, or something derived from them. Its captured output is committed beside it as
`<name>.json`, and the page shows both between a `data-example:start <name>` / `end` marker pair.
A package regenerates them with a `docs:examples` script that boots its test case once per example
(`DataExamples::main()`, writing only with `--write`), and verifies them with one test: `problems()`
for the fixtures and page blocks, and `drift()` per example for the capture. The class docblock is
the reference.

Start with [`AGENTS.md`](AGENTS.md); the contract docblocks are the reference.
Tests: `composer test`.
