# Influx test suite

One Codeception suite, all pure-PHP unit tests. No Craft boot, no DB.

```sh
composer install
composer test           # codecept run unit
```

## What's covered

Lives in `tests/unit/`. Tests build a Field strategy or service by hand, pass
it a small in-memory feed payload + mapping config, and assert on the parsed
value. They run in ~150ms with no external state.

- `fields/LightswitchTest.php` — `options.truthy`, falsey coercion, boolean pass-through
- `fields/DropdownTest.php` — `options.valueMap`, pass-through, default fallback
- `fields/DefaultFieldTest.php` — `Hash::get` node resolution, default fallback
- `fields/CompareTest.php` — `Field::hasChanged` semantics
- `fields/FieldsServiceTest.php` — registry resolution + parent-chain walk
- `models/LinkTest.php` — `matchValue`, `matchAttribute`, `siteHandles`, `getConfig`

Strategies that talk to `Element::find()` (Relation, Assets) aren't covered
here — testing them at unit level would mean mocking half of Craft.

## Why there's no feature suite

A `feature/` suite using `craftcms/test-framework` (the canonical Craft 5
testing path) used to live here. It was removed because:

1. **Upstream is broken.** `craftcms/cms` ships `\craft\test\Craft` which
   extends `Codeception\Module\Yii2`. Craft pins `module-yii2:^1.1.9`, which
   needs `lib-innerbrowser:4.0.1` → `phpunit:^10`. Modern
   `codeception/module-asserts:3.3+` needs `phpunit:^11`. The chain is
   internally inconsistent.
2. **Even when the deps resolve** (downgrade `module-asserts` to 3.0), the
   `Install` migration in `\craft\test\TestSetup::setupCraftDb` fails silently
   inside `ob_start()` — Craft 5's tables never get created and every
   downstream call (`installPlugin`, `projectconfig` read, etc.) crashes.
3. **The pattern is canonical on paper, broken in practice.** The most
   prominent Craft 5 plugin that even *tries* this — Verbb's Feed Me —
   doesn't actually run their tests in CI. Their CI workflow runs ECS,
   PHPStan, and Prettier only.

If/when `craftcms/cms` ships a working test harness for Craft 5, the
lifecycle behaviours that used to be covered (sync events, processing
whitelist, paginator, siteEndpoints, offset, beforeItem hooks, cooldown) can be
ported back as integration tests.
