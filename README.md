# Influx

YAML-configured remote JSON feeds for Craft CMS. A lighter, file-config alternative to FeedMe that **hydrates existing element types** (Entries, Calendar Events, Commerce Products, ...) instead of owning its own element type.

## Why another feed plugin

FeedMe carries a lot of historical surface area (XML, CSV, complex UIs, project-config quirks). Influx makes a few opinionated cuts:

- **YAML-only configuration.** Feeds live in `config/influx/*.yaml` and are version-controlled. No DB-backed configs, no Project Config writes, no per-environment drift.
- **JSON only.** One transport, one parser.
- **Hydrates, doesn't own.** Influx writes to whatever element type you point it at. Hooking it to Solspace Calendar's `Event` is a target adapter, not a fork.
- **Change-detection before save.** Each mapping reports whether it would change anything; unchanged elements skip the save entirely.
- **`.env`-backed auth headers** resolved at fetch-time.
- **Per-language endpoints in a single feed.** One feed can fan out across Craft sites and write localized values onto the same canonical element.

## Status

First-cut vertical slice. Loads YAML, syncs JSON onto Entries, logs every item, exposes a console command and a per-entry "Sync from remote" button. **Not yet** implemented — see [Roadmap](#roadmap).

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2+

## Installation

```bash
composer require tdm/craft-influx
./craft plugin/install influx
```

## Quick start

Create `config/influx/news.yaml`:

```yaml
handle: news
name: News
elementType: craft\elements\Entry
elementCriteria:
  section: news
  type: article
endpoint: $REMOTE_NEWS_URL
headers:
  Authorization: 'Bearer $REMOTE_NEWS_TOKEN'
rootNode: data.items
match:
  attribute: importId
  source: id
mappings:
  title:    { type: PlainText, node: title }
  slug:     { type: PlainText, node: slug }
  importId: { type: PlainText, node: id }
```

Then:

```bash
./craft influx/sync news
```

A worked-example YAML showing every option lives at [`src/resources/example.yaml`](src/resources/example.yaml).

## Concepts

### Targets

A `target` is an adapter for one element type. The plugin ships `EntryTarget` (for `craft\elements\Entry`). Third-party plugins register their own:

```php
use TDM\Influx\Influx;
use TDM\Influx\services\TargetsService;

Event::on(
    TargetsService::class,
    TargetsService::EVENT_REGISTER_TARGETS,
    fn($event) => $event->types[] = MyCalendarTarget::class,
);
```

A target implements `ElementTargetInterface`: it knows how to find an existing element by match value, build a fresh one (with all the type-specific required attributes set), and handle disable/delete/delete-for-site.

### Mappings

A `mapping` reads one field worth of data off a remote item and applies it to the element. Each mapping declares whether its incoming value would change the element — that's how Influx decides to skip the save when nothing's different.

Built-in: `PlainText`. Add more by implementing `MappingInterface` and registering via `MappingService::EVENT_REGISTER_MAPPINGS`.

### Match

Every feed needs a match config: `attribute` is the field/handle on the element used as a stable key (typically a custom plain-text field called `importId`), `source` is the path into the JSON item that provides the value. Influx looks up the existing element by this attribute across all sites — that's how multi-language feeds land on the same canonical entry.

### Multi-site

Set `siteEndpoints` and the feed runs once per site. The element is matched once across all sites, so each site's data lands on the right localized row of the same element.

### Events

Hook into any stage:

- `SynchronizationService::EVENT_BEFORE_SYNC_FEED` / `EVENT_AFTER_SYNC_FEED`
- `SynchronizationService::EVENT_BEFORE_ITEM` — set `$event->skip = true` or swap `$event->element` to redirect
- `SynchronizationService::EVENT_AFTER_ITEM_MAPPING` — mappings have been applied but the element hasn't been saved
- `SynchronizationService::EVENT_AFTER_ITEM` — `$event->action` is `created` / `updated` / `unchanged` / `skipped` / `error`
- `TargetsService::EVENT_REGISTER_TARGETS`
- `MappingService::EVENT_REGISTER_MAPPINGS`

## CLI

```
./craft influx/sync <handle[,handle,...]>     # one or more feeds
./craft influx/sync --all                     # everything
./craft influx/sync news --ago=hour           # apply a named "ago" preset
```

## Design decisions

- **YAML files, not Project Config.** Feed configs are intended for devs only. The CP shows a list, exposes "Duplicate" (writes a new YAML file the dev then commits), shows logs, and triggers runs — it never edits feed structure.
- **One feed = one canonical element across all sites.** Multi-site feeds share the same match value across `siteEndpoints`; per-site Craft rows on that element receive site-localized data.
- **Change detection is mapping-driven.** Each mapping implements `hasChanged()` because a single `==` against the element value gives false-positives on relations, dates, matrix/super-table.

## Roadmap

Not in the first cut, in roughly the order they'd be added:

- [ ] Delete/disable/delete-for-site reconciliation for items that have left the remote feed.
- [ ] Queue-job-based runs and batching (`batchSize` is honoured by config but the engine currently runs inline).
- [ ] CP feeds list, view, and duplicate buttons (the controller skeleton exists; templates don't).
- [ ] Logs UI (the data is in place, the templates aren't).
- [ ] Webhook entry point for push-style feeds.
- [ ] Mappings beyond `PlainText`: Number, Lightswitch, Date, Entries (relations), Categories, Tags, Assets, Matrix, SuperTable.
- [ ] Solspace Calendar target adapter (and example tests).
- [ ] Plain old assertion tests against a recorded fixture.

## License

MIT.
