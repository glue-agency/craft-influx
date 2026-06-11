# Influx

Connect Craft elements to external JSON APIs. A lighter, Project-Config-backed alternative to FeedMe that **hydrates existing element types** (Entries, Calendar Events, Commerce Products, …) instead of owning its own element type.

## Why another sync plugin

FeedMe carries a lot of historical surface area (XML, CSV, complex UIs, project-config quirks). Influx makes a few opinionated cuts:

- **Project Config is the source of truth.** Links live under `influx.links.{uid}` and round-trip to YAML the same way sections, entry types, and volumes do — full diff, full deploy story, full `allowAdminChanges` gating.
- **JSON only.** One transport, one parser.
- **Hydrates, doesn't own.** Influx writes to whatever element type you point it at. Hooking it to Solspace Calendar's `Event` is a target adapter, not a fork.
- **Change-detection before save.** Each mapping reports whether it would change anything; unchanged elements skip the save entirely.
- **`.env`-backed auth headers** resolved at fetch-time.
- **Per-language endpoints in a single link.** One link can fan out across Craft sites and write localized values onto the same canonical element.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require glue-agency/craft-influx
./craft plugin/install influx
```

## Quick start

1. Open `Influx → Links` in the CP and click **New link** (requires `allowAdminChanges`).
2. Fill in the form. The shape mirrors Craft's own Sections / Entry Types editors.
3. Save. The link is written to Project Config; commit the resulting YAML in `config/project/`.
4. Trigger a sync from the link page, the entry edit page, or the CLI:

```bash
./craft influx/sync news               # one link by handle
./craft influx/sync --all              # everything
./craft influx/sync news --ago=hour    # use a named "ago" preset
```

### Migrating from Feed Me

Existing [Feed Me](https://github.com/craftcms/feed-me) feeds can be converted to Influx links. The command reads the `feedme_feeds` table directly, so Feed Me doesn't need to be enabled — just installed at some point:

```bash
./craft influx/import-feed-me            # list available feeds
./craft influx/import-feed-me 1,3        # import specific feeds
./craft influx/import-feed-me --all      # import everything
./craft influx/import-feed-me 1 --dry-run  # preview the link config without saving
./craft influx/import-feed-me 1 --force    # save even when the link doesn't validate
```

The conversion is best-effort: everything that can't be carried over (Matrix block mappings, parent entries, non-JSON feed types, ...) is reported as a warning so you can finish the link in the builder.

Feeds saved by Feed Me 4, 5 and 6 all convert — the stored shape is identical across those majors except for a few renamed handles (e.g. `authorId` → `authorIds`), which the importer accepts interchangeably since rows of different vintages coexist after upgrades.

## Concepts

### Targets

A `target` is an adapter for one element type. The plugin ships `EntryTarget` (for `craft\elements\Entry`). Third-party plugins register their own:

```php
use GlueAgency\Influx\services\TargetsService;

Event::on(
    TargetsService::class,
    TargetsService::EVENT_REGISTER_TARGETS,
    fn($event) => $event->types[] = MyCalendarTarget::class,
);
```

A target implements `ElementTargetInterface`: find existing element by match value, build a fresh one (with all the type-specific required attributes set), and handle disable/delete/delete-for-site.

### Mappings

A `mapping` reads one field worth of data off a remote item and applies it to the element. Each mapping declares whether its incoming value would change the element — that's how Influx decides to skip the save when nothing's different.

Built-in: `PlainText`. Add more by implementing `MappingInterface` and registering via `MappingService::EVENT_REGISTER_MAPPINGS`.

### Match

Every link needs a match config: `attribute` is the field/handle on the element used as a stable key (typically a custom plain-text field called `importId`), `source` is the path into the JSON item that provides the value. Influx looks up the existing element by this attribute across all sites — that's how multi-language links land on the same canonical entry.

### Multi-site

Set per-site endpoints and the link runs once per site. The element is matched once across all sites, so each site's data lands on the right localized row of the same element.

### Events

Hook into any stage:

- `LinksService::EVENT_BEFORE_SAVE_LINK` / `EVENT_AFTER_SAVE_LINK`
- `LinksService::EVENT_BEFORE_DELETE_LINK` / `EVENT_AFTER_DELETE_LINK`
- `SynchronizationService::EVENT_BEFORE_SYNC_LINK` / `EVENT_AFTER_SYNC_LINK`
- `SynchronizationService::EVENT_BEFORE_ITEM` — set `$event->skip = true` or swap `$event->element` to redirect
- `SynchronizationService::EVENT_AFTER_ITEM_MAPPING` — mappings have been applied but the element hasn't been saved
- `SynchronizationService::EVENT_AFTER_ITEM` — `$event->action` is `created` / `updated` / `unchanged` / `skipped` / `error`
- `SynchronizationService::EVENT_REGISTER_ENDPOINT_TOKENS` — mutate `$event->tokens` to add / override / remove tokens substituted into the link's Resource Endpoint URL
- `SynchronizationService::EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS` — append entries to `$event->suggestions` so plugin-contributed tokens show up in the edit-screen "Append token" picker
- `TargetsService::EVENT_REGISTER_TARGETS`
- `MappingService::EVENT_REGISTER_MAPPINGS`

### Integrations

Code that exists to play nice with *other* plugins lives under `src/integrations/`, one sub-namespace per plugin:

- `integrations/feedme` — converts [Feed Me](https://github.com/craftcms/feed-me) feeds into Influx links (see [Migrating from Feed Me](#migrating-from-feed-me)).
- `integrations/calendar` *(planned)* — an `EventTarget` for Solspace Calendar's Event element type, registered when the plugin is installed.

Anything in there treats the other plugin as optional: integrations read its tables or registered services defensively and never make Influx depend on it being installed.

## Design decisions

- **Project Config, not custom YAML.** Earlier drafts wrote feed YAML to `config/influx/`. That worked but reinvented the wheel — Craft's Project Config already does YAML round-tripping, `allowAdminChanges` gating, change tracking, and deploy ergonomics. Influx uses it.
- **One link = one canonical element across all sites.** Multi-site links share the same match value across per-site endpoints; per-site Craft rows on that element receive site-localized data.
- **Change detection is mapping-driven.** Each mapping implements `hasChanged()` because a single `==` against the element value gives false-positives on relations, dates, matrix/super-table.

## Roadmap

- [ ] Delete/disable/delete-for-site reconciliation for items that have left the remote feed.
- [ ] Queue-job-based runs and batching (`batchSize` is honoured by config but the engine currently runs inline).
- [ ] Mappings beyond `PlainText`: Number, Lightswitch, Date, Entries (relations), Categories, Tags, Assets, Matrix, SuperTable.
- [ ] Webhook entry point for push-style links.
- [ ] Solspace Calendar target adapter.

## License

MIT.
