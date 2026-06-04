# Influx test suite

Two Codeception suites, two distinct levels of "what's being tested":

| Suite     | What it covers                                    | Boots Craft? | Runs today? |
|-----------|---------------------------------------------------|--------------|-------------|
| `unit`    | Pure Field-strategy logic (parseField, hasChanged) | No           | Yes          |
| `feature` | End-to-end sync against a real Craft test app     | Yes          | After harness setup |

Both are written in PHPUnit-style classes extending `Codeception\Test\Unit`,
using the standard Codeception layout (`tests/unit/`, `tests/feature/`,
`tests/_support/`).

## Running

```sh
composer install
composer test           # whole suite
composer test-unit      # just unit (always green-able)
composer test-feature   # boots Craft — see below
```

## Unit suite (no Craft)

Lives in `tests/unit/`. These tests build a Field strategy by hand, pass it a
small in-memory feed payload + mapping config, and assert on the parsed value.
No DB, no `Craft::$app`, no project config. They're the first thing to make
pass when iterating on a strategy.

Covered today:

- `fields/LightswitchTest.php` — `options.truthy`, falsey coercion, boolean pass-through
- `fields/DropdownTest.php` — `options.valueMap`, pass-through, default fallback
- `fields/DefaultFieldTest.php` — `Hash::get` node resolution, default fallback
- `fields/CompareTest.php` — `Field::hasChanged` semantics
- `fields/FieldsServiceTest.php` — registry resolution + parent-chain walk
- `models/LinkTest.php` — `matchValue`, `matchAttribute`, `siteHandles`, `getConfig`

Strategies that talk to `Element::find()` (Relation, Assets) live in the
feature suite instead — testing them at unit level would mean mocking
half of Craft.

## Feature suite (boots Craft)

Lives in `tests/feature/`. Uses `craftcms/test-framework` to spin up a real
Craft 5 application against a throwaway database. Each test runs inside a
transaction that's rolled back at teardown, so they don't leak state.

### Prerequisites

1. A test database. Copy `tests/.env.example` → `tests/.env` and edit. Use a
   DB you don't mind being recreated.
2. A seed project config. See `tests/_craft/config/project/README.md` for the
   sections, fields, volumes and groups the tests assume. Easiest path: export
   a snapshot from a real Craft install with the required surface, then trim.
3. `composer install --dev` — pulls in `craftcms/test-framework`,
   `codeception/codeception`, `codeception/module-asserts`, and `phpunit`.

### What's covered

- `sync/SyncLinkLifecycleTest.php` — the headline file.
  - create, update, unchanged, skipped paths
  - `processing` whitelist gates create / update
  - trigger label (`console` / `cp` / `queue` / `element`) lands on the log
  - `beforeSyncLink` cancellation via `isValid = false`
  - `beforeItem` skip + element-swap
  - Event ordering: `beforeSyncLink` → per-item `beforeItem` →
    `afterItemMapping` → `afterItem` → `afterSyncLink`
  - `afterItem.action` matches the log row (`created` / `updated` / `unchanged`)
  - `afterSyncLink` carries aggregate counters
  - Paginator follows `paginatorNode`
  - `siteEndpoints` produces one fetch per site
  - `ago` presets translate into query params

- `sync/PerFieldUpdateTest.php` — one test per field type:
  - Native: `title`, `slug`, `status → enabled`
  - PlainText (Default strategy)
  - Lightswitch — truthy coercion + custom truthy list
  - Dropdown — direct + `valueMap`
  - Assets — `mode: id`, `mode: url` (filename match), upload-from-URL
  - Assets recursive native sub-fields (`alt`, `title`)
  - Entries relation — `match: title` + create-on-miss (with and without
    `options.group`)
  - Categories relation — `match: slug`, scoped to the field's source group
  - Tags relation — auto-create in the field's group

- `sync/CompareSemanticsTest.php` — integrated `hasChanged` proofs:
  - Unchanged items DO NOT bump `dateUpdated`
  - Single-field change still marks the whole item as updated
  - Assets compare is order-insensitive (`[A, B]` vs `[B, A]` is unchanged)

- `sync/ElementSaveEventsTest.php` — Craft's `Elements::EVENT_BEFORE_SAVE_ELEMENT`
  / `EVENT_AFTER_SAVE_ELEMENT` fire for create + update, and DO NOT fire for
  unchanged / skipped. This is the contract third-party listeners depend on.

- `sync/SyncElementTest.php` — per-entry "Sync from remote" path:
  - `fetchOne` is called with the matchValue
  - Cooldown is stamped on success
  - Missing matchValue raises `InfluxException`
  - Log trigger is `element`

### Why `DataService` is stubbed in `FeatureTestCase`

`SynchronizationService` calls `Influx::getInstance()->data->fetch(...)`. To
keep tests deterministic (and away from the network), `FeatureTestCase::_before`
replaces the `data` plugin component with `StubDataService`:

```php
Influx::getInstance()->set('data', $this->data);
```

Tests then queue responses on `$this->data->queueFetch(...)` /
`queueFetchOne(...)` / `queueFetchUrl(...)`. Everything downstream
(SynchronizationService, FieldsService, EntryTarget, AssetUploadService,
LogsService, real `getElements()->saveElement`) runs unmodified.

The seam was chosen at `DataService` because:

- It's the only thing that talks to the network.
- It's a configured component, so `set()` swaps it cleanly.
- Nothing in the per-field strategies references it directly — they just see
  the resolved array.

Other components (assetUpload in one test, fields when adding a custom
strategy) are swapped the same way.

## When tests need a wider surface

If you add a sync feature that needs a new field handle / section / category
group, update the project config snapshot under `tests/_craft/config/project/`
and the index in its README. Don't mock — the whole point of the feature
suite is to run against the same Craft API the plugin sees in production.
