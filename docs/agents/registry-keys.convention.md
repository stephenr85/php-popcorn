# Registry keys — dotted lowercase-kebab

**Status:** canon for every registry root and every key written into one, in this package and in
every package or host that declares an `#[IsRegistry]` root.
**Grammar:** `Rushing\Popcorn\Registries\Key` (`src/Registries/Key.php`). The grammar says what is
*legal*; this document says what to *write*. They differ on purpose.

## The rule

> **Write registry keys as dotted lowercase-kebab: `.` between segments, `-` between words.**
> An underscore is legal only in a segment mirrored verbatim from outside the registry.

```php
'beam.calendars.event-kinds'   'beam.notifications.recipient-kinds'   'composition.intake-composers'
```

Not `beam.calendars.event_kinds`, not `beam.calendars.eventKinds`. This applies to the root you
declare and to the keys you choose for the entries under it.

## Why the grammar is wider than the rule

`Key::SEGMENT_PATTERN` admits `-`, `_` and `:` inside a segment. It does not fold one into another,
so `event_kinds` and `event-kinds` are two different keys, and nothing will tell you that you
picked the wrong one. The grammar was widened so that live keys a registry does not own would stop
being rejected, not to make the spellings interchangeable:

- a leaf lifted from a customer schema (`grounding.source.context_scope`);
- a type-tagged instance id (`resource:name`).

Anything newly written uses kebab.

## When an underscore is right: the key is mirrored

A segment may keep an underscore when the registry did not choose its spelling. It is copied from
an authority outside the registry, and renaming it would break that authority:

- **Schema keywords.** The keys under `beam.notifications.recipient-kinds` are `to`, `to_roles` and
  `to_teams`, because they are the `x-beam-notify` keyword's own property names. A schema author
  writes `to_roles:`, and the registry key must match it character for character.
- **Customer or third-party identifiers.** Examples are a field name from an imported schema, or a
  column name read back from storage.

If you can rename the segment without touching anything outside the registry, it is not mirrored,
so kebab it.

## A config-backed registry: the root is the config path

A `ConfigRegistry` (`rushing/laravel-popcorn`) stores its entries in the config repository, under
`configKey()`. Keep
`configKey()` equal to the root, so that the array key in the published config file is the root's
last segment, spelled the same way:

```php
#[IsRegistry(root: 'beam.calendars.event-kinds', ...)]
class EventKindRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'beam.calendars.event-kinds';
    }
}

// config/beam/calendars.php
'event-kinds' => [
    'kind.event' => EventData::class,
],
```

So `config('beam.calendars.event-kinds')` and the registry root name the same thing. A reader who
finds one can find the other, without a mapping to keep in step. Sibling scalar settings in the same
file (`spawn_driver`, `channel_source`) are ordinary Laravel config, not registry roots, and keep
the file's own spelling.

Mirroring a snake config key is how underscore roots got into the estate. Three `ConfigRegistry`
roots took their config key's spelling: `event_kinds`, `action_handlers` and `recipient_kinds`. All
three were renamed to kebab together with their config keys, with no alias.

## No aliases on a rename

A registry has no alias step (see `Registry`, *"No aliases"*), and a key has no fold. Renaming a
root therefore means changing the declaration, `configKey()`, the published config key, every
registrant and reader, and the root's docs page (`docs/registries/<root>.mdx`, its `segment:` and
the URLs that link to it) together. Before you rename one, ask what persisted it: a key that is
stored, such as an event name or a database column, needs a migration as well as a rename.

## Related

- `docs/adr/0002-registry-membership-is-baked-and-resolved-lazily.md`: how roots are discovered.
- `splicewire/splicewire-app` `docs/agents/resource-keys.convention.md`: the plural-kebab rule for
  particle **resource** keys. That is a different namespace, with the same house spelling.
