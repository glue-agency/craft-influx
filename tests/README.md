# Influx test suite

One Codeception suite, all pure-PHP unit tests. No Craft boot, no DB.

```sh
composer install
composer test           # codecept run unit
```

## What's covered

Lives in `tests/unit/`, in directories mirroring the `src/` namespaces. Tests
build a strategy, model or collaborator by hand, pass it a small in-memory feed
payload + mapping config, and assert on the result. They run in well under a
second with no external state. Each test class carries a docblock stating the
behaviour it pins — read those first.

- `fields/` — the mapping strategies: truthy coercion, option match-by-label,
  date formats, the fallback strategy, relation lookup + caching, the
  group-scoped relation base, Matrix block trees, `hasChanged()` semantics, and
  strategy resolution (parent-chain walk to the option-field base, fallback to
  `DefaultField`)
- `targets/` — the shared `AbstractElementTarget` behaviour, `EntryTarget`'s
  query scoping and native-field visibility, and mapping pruning
- `sync/item/` and `sync/run/` — the per-item pipeline (result aggregation,
  remote-item decoding, the sync decision, the element lookup cache) and per-run
  orchestration (batch state, the log-item buffer, the missing-elements sweep's
  plan/act split, the progress denominator, queued site fan-out)
- `models/`, `schema/` — Link config round-trips, claim scoping, the builder
  payload, mapping config, and the `MappableField` descriptor + `SchemaBuilder`
- `services/`, `web/`, `enums/`, `auth/`, `data/`, `helpers/` — the registry
  base, presenters and their wire shapes, the enum-derived UI vocabulary, auth
  strategies, feed paging, and the comparison normaliser
- `integrations/craftcms/feedme/` — the Feed Me feed → Influx link conversion

Anything needing a booted Craft (a real `Element::find()`, a `saveElement()`) is
either stubbed at a seam or left out — testing it at unit level would mean
mocking half of Craft.

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
