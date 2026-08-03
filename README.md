# Influx

Connect Craft elements to external JSON APIs. A lighter, Project-Config-backed alternative to FeedMe that **hydrates existing element types** (Entries and Users today, any element type through a target adapter) instead of owning its own element type.

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
4. Trigger a sync from the Links overview, the entry edit page, or the CLI:

```bash
./craft influx/sync news                # one link by handle
./craft influx/sync news,events         # several, comma-separated
./craft influx/sync --all               # everything
./craft influx/sync news --offset=hour  # use the "hour" offset preset from the link config
./craft influx/sync news --site=fr      # only the "fr" site-specific endpoint
```

Runs also trigger from the CP — the sync action on a link's row under **Influx → Links**, or the "Sync from remote" action on a synced entry, offered to users holding the *Sync elements from a remote link* permission. Link-level CP runs are queued (one job per site, one feed page per step, so large feeds don't time out a request); single-element runs and console runs are synchronous. Unless logging is switched off in the plugin settings, every run produces a log under **Influx → Logs** with a per-item drill-down. The **Debug** screen, reachable from a link's row, dry-runs the feed against the current mapping without writing anything — for building/troubleshooting a link before it goes live.

On the edit screen of any element a link writes to, each actively-mapped field carries a small Influx mark next to its label, tooltipped "This field is updated by Influx." — so an editor sees at a glance which values the next sync may overwrite. Purely informational, and not permission-gated.

### Migrating from Feed Me

Existing [Feed Me](https://github.com/craftcms/feed-me) feeds can be converted to Influx links. The command reads the `feedme_feeds` table directly, so Feed Me doesn't need to be enabled — just installed at some point:

```bash
./craft influx/feed-me            # list available feeds
./craft influx/feed-me 1,3        # import specific feeds
./craft influx/feed-me --all      # import everything
./craft influx/feed-me 1 --dry-run  # preview the link config without saving
./craft influx/feed-me 1 --force    # save even when the link doesn't validate
```

The conversion is best-effort: everything that can't be carried over (parent entries, non-JSON feed types, ...) is reported as a warning so you can finish the link in the builder. Matrix block mappings do convert, but only their custom fields — Feed Me's per-block-type keys other than `fields` are warned about and dropped.

Feeds saved by Feed Me 4, 5 and 6 all convert — the stored shape is identical across those majors bar two divergences the importer accepts interchangeably: the entry-author handle (`authorId` through v5, `authorIds` since v6) and relation `options.match` values (raw content-table column names through v5, bare handles since v6). Feed Me never rewrites stored `fieldMapping` JSON on upgrade, so the vintage is a property of the row rather than of the installed version — which is why there's one converter instead of one per major.

## Concepts

### Registries

Three things are pluggable — element **targets**, mapping **field strategies** and **auth strategies** — and all three work the same way. Write a class implementing the extension point's contract, then hand it to the registry either by listening to its registration event (from your plugin's `init()`):

```php
use GlueAgency\Influx\services\TargetsService;
use yii\base\Event;

Event::on(
    TargetsService::class,
    TargetsService::EVENT_REGISTER_TARGETS,
    fn($event) => $event->targets[] = MyCalendarTarget::class,
);
```

…or imperatively:

```php
use GlueAgency\Influx\Influx;

Influx::getInstance()->targets->register(MyCalendarTarget::class);
```

The event payload arrives pre-seeded with the built-ins, so a listener can append to the list, **replace** a built-in (register a class declaring the same key — element type / Craft field class / auth `type`) or **remove** one by filtering the array. Registration resolves lazily, once, on first use.

Each registry hands out one shared prototype instance per registered class, built through `Craft::createObject()` — so your class may declare constructor dependencies the container can resolve. `Influx::getInstance()->targets->all()` (likewise `->fields->all()`, `->auth->all()`) returns them keyed by their declared key.

### Targets

A `target` is an adapter for one element type. The plugin ships `EntryTarget` (for `craft\elements\Entry`) and `UserTarget` (for `craft\elements\User`).

> **Note:** `UserTarget` is under active development — treat it as experimental. Its mapping options (group membership, user-specific attributes) and behaviour may still change before release.

Third-party plugins register their own through `TargetsService::EVENT_REGISTER_TARGETS` or `->targets->register()` (see [Registries](#registries)); targets are keyed by the `elementType()` they declare.

A target implements `ElementTargetInterface`: find existing element by match value, build a fresh one (with all the type-specific required attributes set), and own every write to it — `save()` plus disable / disable-for-site / delete / delete-for-site. Every write to the synced element routes through the target instead of Craft's element API, so a target can save with whatever flags its element type needs; the base implementation is Craft's own save with validation off (the feed is authoritative — same policy Feed Me imports run on). Related elements a mapping creates on the fly are the strategies' own business, not the target's.

Three static capabilities let a target describe its element type to the builder and the sync engine:

- **`supportsMultiSite()`** — whether links can carry site-specific endpoints and be swept per-site. Localizable types (Entry) return `true`; global, non-localizable ones (User) return `false`, so their links always run once against a single endpoint and the CP hides the site-specific controls. `Link` rejects site endpoints configured against a non-multi-site target as a server-side backstop.
- **`criteriaKeys()`** — the `elementCriteria` keys the type scopes on, rendered as extra dropdowns on the builder's General tab (Entry uses `['section', 'type']`; User has none). The target owns those key names as constants (`EntryTarget::CRITERIA_SECTION`), and stored criteria are read through `Link::criterion($key)`.
- **`supportsSweeping()`** — whether links to this type can be swept for elements missing from the feed. A sweep acts on the complement of what a run saw, so it needs a target that can enumerate "everything this link owns" (`missingElementsQuery()`). Types with no scoping dimension (User: the candidate set would be every user in the system) return `false`, and the builder then leaves the disable-/delete-missing policies out of the processing checkboxes; a stored policy from before that gets a reported skip in the run's log rather than silently doing nothing.

A target that partitions its element type also implements **`claimCells()`** — the comparable cells two links intersect on when Influx warns that both define a resource mapping for the same elements (entries expand to `"{section} {entryType}"`; the base reports one `*` sentinel, so two links of an unpartitioned type always overlap).

A target also reports which fields a link may map to: `getMappableFields()` returns a `list<MappableField>` — its element type's native attributes, declared with the same `SchemaBuilder` the mapping extras use, plus the custom fields on the resolved field layout, grouped the way the element editor groups them. Natives the element type hides are left out by omission: an entry type with `hasTitleField` off never offers `title`. A stored mapping for a handle the target no longer offers is pruned the next time the link is saved.

### Mappings

A `mapping` reads one field worth of data off a remote item and applies it to an element field. Each mapping declares whether its incoming value would change the element (`hasChanged()`) — that's how Influx decides to skip the save when nothing's different.

Built-in strategies, keyed by Craft field class and registered via `FieldsService::EVENT_REGISTER_FIELDS`:

- **`Lightswitch`**, **`Date`**, **`Dropdown`** (covers option fields generally, e.g. Radio Buttons, Checkboxes) — truthy/falsy coercion, configurable date-format parsing, match-by-label-or-value.
- **`Entries`**, **`Categories`**, **`Tags`**, **`Users`** — relation fields with a match-by strategy (id, title, slug, or any unique attribute), with optional create-on-the-fly when nothing matches.
- **`Assets`** — matches by id or by URL/filename, with best-effort fallback when a CDN host changes, and optional download-on-import when nothing matches. Sub-fields (alt, title, …) write back onto the matched asset.
- **`RichText`** — CKEditor/Redactor-style fields.
- **`Matrix`** — maps a remote sub-array to blocks, one child-mapping tree per block type. Every sync fully replaces the field's blocks from the feed (no per-block merge or reordering yet).
- **`Table`** — one sub-mapping per column (keyed by column id, so a handle rename can't orphan it), values zipped by index into rows. Full-replace, like Matrix; change detection normalizes per column type so a checkbox or date column doesn't churn.

`DefaultField` catches everything no strategy claims — plain-value fields (Plain Text, Number, Email, URL, …) and any Craft field type without a dedicated strategy: a direct `setFieldValue()`. It declares no Craft field class, so it isn't a registered strategy; the registry holds it apart as the fallback, and it never shows up in `->fields->all()`.

Add more by extending `GlueAgency\Influx\fields\Field`, declaring the Craft field class it handles via `craftFieldClass()` (a base class such as `BaseOptionsField` covers a whole family — lookups walk the parent chain), and registering it as any other extension point (see [Registries](#registries)).

The source-node dropdowns list what the fetched sample discovered — and the sample is one page. A key that only shows up on a later page can be mapped anyway: type it into the node search and pick the "Custom node" row the picker offers for a path it doesn't know. It saves like any other node and reads as a missing mapping until a page carrying it is fetched; at sync time it resolves per item, so items that do carry it get the value.

The builder's details sidebar reports where the sample stands and how much of the tree is mapped, and its **Auto-match** maps every field whose handle matches a node in the sample. It only ever fills a field that has no source node and no "use default" — a mapping you made is never overwritten — and the rows it filled carry an "auto" badge until you touch them. Nothing about that badge is stored: an auto-matched mapping is an ordinary one.

A strategy's mapping-extras UI is declarative: `schema()` returns a `SchemaBuilder`, and the CP renders it generically — no Vue changes needed to add a control. For a node type the builder doesn't ship, `SchemaBuilder::node('myType', [...])` passes it through; the CP renders an unrecognised type as a labeled text input on the node's handle rather than dropping it.

### Match

Every link needs a match config: `attribute` is the field/handle on the element used as a stable key (typically a custom plain-text field called `importId`). There's no separate match-source path — the match value is always read from that same field's own mapping node, so the field that identifies an item is mapped like any other field. Influx looks up the existing element by this attribute — unscoped across sites for a single-endpoint link, scoped to the site being processed for a per-site run. Either way a multi-language link converges on one canonical element, provided the match field isn't translatable so every site's row carries the same key.

### Multi-site

Set per-site endpoints and the link runs once per site — one queue job and one log per site, in the configured order. Each pass matches the element inside the site it's processing and writes to that site's localized row, so every endpoint feeds the same canonical element. Element types whose target reports no multi-site support (Users) always run once against a single endpoint.

### Concurrency

**Can a per-site fan-out create the same element twice?** No — double writes to the same site group are protected by a mutex lock on the jobs (`influx:sync:{handle}`), keyed on the link handle rather than the site, so one site's pass always finishes before the next starts looking.

**Can two different links?** Yes — the lock doesn't span links. Run links that write the same section in one sequential `influx/sync a,b,c` invocation.

**Does it guarantee one element per match value?** No. It serialises runs; finding the element from the next site's pass still depends on the section's propagation, which is Craft's setting, not the plugin's.

### Offset presets

A link can declare named sliding-window presets (`offset: { hour: { since: '-1 hour', queryParam: modified_since } }`) so a scheduled `--offset=hour` run only asks the feed for what changed recently, instead of re-fetching everything every time. `since` and `queryParam` are required; an optional `format` sets how the cutoff is formatted (ISO 8601 by default). An offset run never sweeps for missing elements — its seen-set only covers the window.

### Backup

A link can be flagged to take a full database backup (through Craft's own database-backup API, `Craft::$app->getDb()->backup()`) immediately before it runs — cheap insurance for a first sync or a link with delete permissions enabled. A failed backup aborts the run rather than letting a destructive sweep proceed unprotected.

### Auth

Built-in strategies: Basic, Bearer, Custom Header, Query String. Tokens are stored exactly as written and resolved through Craft's `App::parseEnv()` at request time, so writing `$API_KEY` keeps the secret itself in `.env` instead of Project Config. Resolution is deliberately lenient — an unset or empty variable sends an empty credential rather than throwing, since a local environment legitimately leaves one blank. Third-party strategies register via `AuthService::EVENT_REGISTER_AUTH_TYPES` or `->auth->register()` (see [Registries](#registries)).

A strategy implements `AuthStrategyInterface`: three static descriptors of the class — `type()` (the stored discriminator and registry key), `label()` (CP dropdown) and `schema()` (the form the CP renders for it, a `SchemaBuilder`) — plus an instance `apply()` returning the headers / query params for one request. Extending `AbstractAuthStrategy` makes it a Craft model, so per-type validation goes in `defineRules()`. Per request, the link's stored `auth` slice is handed to the constructor as its last argument.

### Events

Hook into any stage:

- `LinksService::EVENT_BEFORE_SAVE_LINK` / `EVENT_AFTER_SAVE_LINK`
- `LinksService::EVENT_BEFORE_DELETE_LINK` / `EVENT_AFTER_DELETE_LINK`
- `SynchronizationService::EVENT_BEFORE_SYNC_LINK` — once per run, and cancellable: `$event->isValid = false` cancels every site that run would cover
- `SynchronizationService::EVENT_AFTER_SYNC_LINK` — once per site log, carrying that site's `siteHandle` and counters
- `SynchronizationService::EVENT_BEFORE_ITEM` — set `$event->skip = true` or swap `$event->element` to redirect
- `SynchronizationService::EVENT_AFTER_ITEM_MAPPING` — mappings have been applied but the element hasn't been saved
- `SynchronizationService::EVENT_AFTER_ITEM` — `$event->action` is `created` / `updated` / `unchanged` / `error`. Skipped items return before this fires, and the missing-elements sweep's outcomes (`disabled`, `deleted`, …) are log rows only, never event payloads
- `EndpointTokensService::EVENT_DEFINE_ENDPOINT_TOKENS` — mutate `$event->tokens` to add / override / remove tokens substituted into the link's Resource Endpoint URL
- `EndpointTokensService::EVENT_DEFINE_ENDPOINT_TOKEN_SUGGESTIONS` — append entries to `$event->suggestions` so plugin-contributed tokens show up in the edit-screen "Insert token" picker
- `TargetsService::EVENT_REGISTER_TARGETS` — mutate `$event->targets` (see [Registries](#registries))
- `FieldsService::EVENT_REGISTER_FIELDS` — mutate `$event->fields`
- `AuthService::EVENT_REGISTER_AUTH_TYPES` — mutate `$event->authTypes` to add auth strategies alongside the built-in Basic / Bearer / Custom Header / Query String
- `Date::EVENT_REGISTER_FORMAT_OPTIONS` — append feed-specific date formats to (or replace) the presets offered in the mapping UI's format picker. Fired off the `Date` class (not a service) and memoized per request, so attach it from your plugin's `init()`

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

Shipped since the alpha: queue-job-based runs (one job per site, one feed page per step, resumable), missing-element reconciliation (disable / disable-for-site / delete / delete-for-site, gated by endpoint shape), and mapping strategies for relations, options, dates, assets, rich text, Matrix, and Table.

Still open:

- [ ] **More element-type targets.** Links can hydrate Entries (`EntryTarget`) today, with `UserTarget` (Users) under active development. Add target adapters for the other element types:
  - [ ] Assets (`craft\elements\Asset`)
  - [ ] Categories (`craft\elements\Category`)
  - [ ] Events — [Solspace Calendar](https://github.com/solspace/craft-calendar)
  - [ ] Products — [Craft Commerce](https://github.com/craftcms/commerce)
  - [ ] Variants — [Craft Commerce](https://github.com/craftcms/commerce)
- [ ] **Strategies for the remaining native field types.** Anything without a strategy falls back to `DefaultField`'s raw write — fine for plain scalar types, wrong for richer ones:
  - [ ] Time (`craft\fields\Time`) — the fallback re-writes the field on every sync: the stored `DateTime` and the feed's string never compare equal
  - [ ] Money (`craft\fields\Money`) — the fallback never detects a change after the first write, so updates are silently skipped
  - [ ] Link (`craft\fields\Link`, Craft ≥ 5.3) — bare URLs work through the fallback; no link-type, label or target support
  - [ ] Addresses (`craft\fields\Addresses`) — nested address elements, unusable through a raw write; needs a Matrix-style strategy with address sub-fields
  - [ ] Content Block (`craft\fields\ContentBlock`, Craft ≥ 5.8) — nested entry, same story; Craft-5-only gating
  - [ ] Plain Text, Email, Icon, Country, Range, Number, Color, JSON — served acceptably by the fallback today; dedicated strategies would only tighten change detection (decimal formatting, un-normalized hex colors, JSON key order)
- [ ] Matrix per-block merge and reordering (today every sync fully replaces a Matrix field's blocks).

## Acknowledgements

Influx is heavily inspired by [Feed Me](https://github.com/craftcms/feed-me) (`craftcms/feed-me`). Its mapping model — per-field-type strategies, relation sub-fields, asset upload-on-import, and change detection before save — follows trails Feed Me blazed. Influx makes different trade-offs (JSON-only, Project Config-backed, hydrating existing element types rather than owning its own), but it stands on Feed Me's shoulders, and the `integrations/feedme` converter exists so you can bring your existing feeds along.

## License

MIT.
