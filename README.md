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

- Craft CMS 4.0+ or 5.0+
- PHP 8.1+

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
./craft influx/sync news                # one link by handle
./craft influx/sync news,events         # several, comma-separated
./craft influx/sync --all               # everything
./craft influx/sync news --offset=hour  # use the "hour" offset preset from the link config
./craft influx/sync news --site=fr      # only the "fr" site-specific endpoint
```

Runs also trigger from the CP — the link's own page, the "Sync from remote" action on a synced element, or **Influx → Links**. CP-triggered runs are queued (one job per site, one feed page per step, so large feeds don't time out a request); console runs are synchronous. Every run produces a log under **Influx → Logs** with a per-item drill-down, and the link's **Debug** tab dry-runs the feed against the current mapping without writing anything, for building/troubleshooting a link before it goes live.

### Migrating from Feed Me

Existing [Feed Me](https://github.com/craftcms/feed-me) feeds can be converted to Influx links. The command reads the `feedme_feeds` table directly, so Feed Me doesn't need to be enabled — just installed at some point:

```bash
./craft influx/feed-me            # list available feeds
./craft influx/feed-me 1,3        # import specific feeds
./craft influx/feed-me --all      # import everything
./craft influx/feed-me 1 --dry-run  # preview the link config without saving
./craft influx/feed-me 1 --force    # save even when the link doesn't validate
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

A `mapping` reads one field worth of data off a remote item and applies it to an element field. Each mapping declares whether its incoming value would change the element (`hasChanged()`) — that's how Influx decides to skip the save when nothing's different.

Built-in strategies, keyed by Craft field class and registered via `FieldsService::EVENT_REGISTER_FIELDS`:

- **`DefaultField`** — fallback for plain-value fields (Plain Text, Number, Email, URL, …) and any Craft field type without a dedicated strategy: a direct `setFieldValue()`.
- **`Lightswitch`**, **`Date`**, **`Dropdown`** (covers option fields generally, e.g. Radio Buttons, Checkboxes) — truthy/falsy coercion, configurable date-format parsing, match-by-label-or-value.
- **`Entries`**, **`Categories`**, **`Tags`**, **`Users`** — relation fields with a match-by strategy (id, title, slug, or any unique attribute), with optional create-on-the-fly when nothing matches.
- **`Assets`** — matches by id or by URL/filename, with best-effort fallback when a CDN host changes, and optional download-on-import when nothing matches. Sub-fields (alt, title, …) write back onto the matched asset.
- **`RichText`** — CKEditor/Redactor-style fields.
- **`Matrix`** — maps a remote sub-array to blocks, one child-mapping tree per block type. Every sync fully replaces the field's blocks from the feed (no per-block merge or reordering yet).

Add more by extending `GlueAgency\Influx\fields\Field` and registering the class via `FieldsService::EVENT_REGISTER_FIELDS`.

### Match

Every link needs a match config: `attribute` is the field/handle on the element used as a stable key (typically a custom plain-text field called `importId`). There's no separate match-source path — the match value is always read from that same field's own mapping node, so the field that identifies an item is mapped like any other field. Influx looks up the existing element by this attribute across all sites — that's how multi-language links land on the same canonical entry.

### Multi-site

Set per-site endpoints and the link runs once per site. The element is matched once across all sites, so each site's data lands on the right localized row of the same element.

### Offset presets

A link can declare named sliding-window presets (`offset: { hour: { since: '-1 hour', queryParam: modified_since } }`) so a scheduled `--offset=hour` run only asks the feed for what changed recently, instead of re-fetching everything every time.

### Backup

A link can be flagged to take a full database backup (via Craft's own `db/backup`) immediately before it runs — cheap insurance for a first sync or a link with delete permissions enabled.

### Auth

Built-in strategies: Basic, Bearer, Custom Header, Query String. Secrets are stored as written (e.g. `$API_KEY`) and resolved from `.env` at request time, never persisted in plain text in Project Config. Third-party strategies register via `AuthService::EVENT_REGISTER_AUTH_TYPES`.

### Events

Hook into any stage:

- `LinksService::EVENT_BEFORE_SAVE_LINK` / `EVENT_AFTER_SAVE_LINK`
- `LinksService::EVENT_BEFORE_DELETE_LINK` / `EVENT_AFTER_DELETE_LINK`
- `SynchronizationService::EVENT_BEFORE_SYNC_LINK` / `EVENT_AFTER_SYNC_LINK`
- `SynchronizationService::EVENT_BEFORE_ITEM` — set `$event->skip = true` or swap `$event->element` to redirect
- `SynchronizationService::EVENT_AFTER_ITEM_MAPPING` — mappings have been applied but the element hasn't been saved
- `SynchronizationService::EVENT_AFTER_ITEM` — `$event->action` is `created` / `updated` / `unchanged` / `skipped` / `error`
- `EndpointTokensService::EVENT_REGISTER_ENDPOINT_TOKENS` — mutate `$event->tokens` to add / override / remove tokens substituted into the link's Resource Endpoint URL
- `EndpointTokensService::EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS` — append entries to `$event->suggestions` so plugin-contributed tokens show up in the edit-screen "Append token" picker
- `TargetsService::EVENT_REGISTER_TARGETS`
- `FieldsService::EVENT_REGISTER_FIELDS`
- `AuthService::EVENT_REGISTER_AUTH_TYPES` — add auth strategies alongside the built-in Basic / Bearer / Custom Header / Query String
- `Date::EVENT_REGISTER_FORMAT_OPTIONS` — append feed-specific date formats to (or replace) the presets offered in the mapping UI's format picker

### Integrations

Code that exists to play nice with *other* plugins lives under `src/integrations/`, one sub-namespace per plugin:

- `integrations/feedme` — converts [Feed Me](https://github.com/craftcms/feed-me) feeds into Influx links (see [Migrating from Feed Me](#migrating-from-feed-me)).

Planned target adapters for [Solspace Calendar](https://github.com/solspace/craft-calendar) and [Craft Commerce](https://github.com/craftcms/commerce) elements (see the [Roadmap](#roadmap)) will register their targets when those plugins are installed, following the same optional-dependency rule.

Anything in there treats the other plugin as optional: integrations read its tables or registered services defensively and never make Influx depend on it being installed.

## Design decisions

- **Project Config, not custom YAML.** Earlier drafts wrote feed YAML to `config/influx/`. That worked but reinvented the wheel — Craft's Project Config already does YAML round-tripping, `allowAdminChanges` gating, change tracking, and deploy ergonomics. Influx uses it.
- **One link = one canonical element across all sites.** Multi-site links share the same match value across per-site endpoints; per-site Craft rows on that element receive site-localized data.
- **Change detection is mapping-driven.** Each mapping implements `hasChanged()` because a single `==` against the element value gives false-positives on relations, dates, and structured fields like Matrix.

## Roadmap

Shipped since the alpha: queue-job-based runs (one job per site, one feed page per step, resumable), missing-element reconciliation (disable / delete / delete-for-site, gated by endpoint shape), and mapping strategies for relations, options, dates, assets, rich text, and Matrix.

Still open:

- [ ] **More element-type targets.** A link can only hydrate Entries today (`EntryTarget`). Add target adapters for the other element types:
  - [ ] Assets (`craft\elements\Asset`)
  - [ ] Categories (`craft\elements\Category`)
  - [ ] Users (`craft\elements\User`)
  - [ ] Events — [Solspace Calendar](https://github.com/solspace/craft-calendar)
  - [ ] Products — [Craft Commerce](https://github.com/craftcms/commerce)
  - [ ] Variants — [Craft Commerce](https://github.com/craftcms/commerce)
- [ ] Matrix per-block merge and reordering (today every sync fully replaces a Matrix field's blocks) and multiple block types in a single mapping row.

## Acknowledgements

Influx is heavily inspired by [Feed Me](https://github.com/craftcms/feed-me) (`craftcms/feed-me`). Its mapping model — per-field-type strategies, relation sub-fields, asset upload-on-import, and change detection before save — follows trails Feed Me blazed. Influx makes different trade-offs (JSON-only, Project Config-backed, hydrating existing element types rather than owning its own), but it stands on Feed Me's shoulders, and the `integrations/feedme` converter exists so you can bring your existing feeds along.

## License

MIT.
