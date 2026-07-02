# Matrix field support (Craft 4 + 5)

Real Matrix block mapping for the feed-import plugin: map a remote sub-array to a
Matrix field's blocks, one child mapping tree per block type, reusing the recursive
`fields`/`nativeFields` machinery that relational sub-mappings already use.

Verified against the installed `craftcms/cms` **5.10.5** (`vendor/.../config/app.php`).
Craft 4 assertions are from docs knowledge and flagged where unverifiable here.

---

## Key research findings (load-bearing)

**Craft 5 serialized-value format** (`vendor/craftcms/cms/src/fields/Matrix.php`, `_createEntriesFromSerializedData`, lines 1688–1912; reached from `normalizeValue` → `setFieldValue`):
- The FLAT shape `[$blockKey => ['type' => <entryType handle>, 'enabled' => bool, 'title' => ?, 'fields' => [...]]]` is accepted directly (lines 1766–1769: when neither `entries`/`blocks`/`sortOrder` is present, `$newEntryData = $value` and `$newSortOrder = array_keys($value)`).
- Feed order is preserved: iteration is over `$newSortOrder`, which for the flat shape is `array_keys($value)` — so **array insertion order == block order**. No explicit `sortOrder` needed.
- New blocks require a valid `type` handle keyed into `getEntryTypes()` (lines 1828–1833) or the row is silently skipped. Unknown block type = dropped block, not an error.
- Block keys `newN` are arbitrary strings; anything not matching an existing block id/uid is treated as a new block. For a full-replace we always use fresh `newN` keys, so every incoming block is created new.
- `fields` are applied via `$entry->setFieldValue($handle, $value)` (line 1891, non-request path) — which means **nested custom fields inside a block go through the exact same `setFieldValue` contract our field strategies already target.**
- Blocks are nested Entry elements persisted by Craft's `NestedElementManager::saveNestedElements()` when the **owner** element is saved. There is NO separate save of block elements from our side.

**Craft 4 serialized-value format** (docs knowledge — NOT verifiable in this vendor tree, which is Craft 5):
- `setFieldValue($handle, [$blockKey => ['type' => <blockType handle>, 'enabled' => bool, 'fields' => [...]]])` with `newN` keys. Same conceptual shape; block model is `craft\elements\MatrixBlock`, block types are `craft\models\MatrixBlockType` reached via `$field->getBlockTypes()`.
- Field-layout discovery: Craft 4 = `MatrixBlockType::getFieldLayout()`; Craft 5 = `EntryType::getFieldLayout()`. Different classes, same `getFieldLayout()` method name.
- Craft 4 block types are discovered via `$matrixField->getBlockTypes()` returning `MatrixBlockType[]` (each with `->handle`, `->name`, `->getFieldLayout()`). Craft 5 via `$matrixField->getEntryTypes()` returning `EntryType[]` (each with `->handle`, `->name`, `->getFieldLayout()`). **This is the single divergence that must go through Compat.**

**Consequence for architecture:** the *serialized value shape* is close enough to build identically on both versions from one `parse()`; the ONE thing that genuinely diverges is **block-type discovery + per-block-type field-layout lookup**, which is a builder-side + parse-side introspection concern. That is the surface Compat must cover.

**Existing recursion reuse:** `MappingApplier::applySubMappings()` + `FieldContext::descend()` already walk `fields`/`nativeFields` against a related element and enforce `FieldContext::MAX_DEPTH = 3`. Matrix child mappings can reuse `descend()` — but with a critical difference: relational sub-mappings descend into a *real persisted element*, whereas a Matrix block is built in memory and its values must be *harvested* into the serialized `fields` array, not written onto a persisted element and saved. This means Matrix cannot reuse `persistSubElement()` (which saves) and cannot straightforwardly reuse `applySubMappings()` (which mutates a live element). See PHP section for the resolution.

---

## Decision points (resolved, not deferred)

- **D1 — Multi-block-type feeds: OUT for v1.** A single Matrix mapping row targets **one fixed block type** (chosen in the builder). One remote sub-array → N blocks, all of the same block type. A discriminator-node ("read the block type from a feed field per row") is explicitly a follow-up. Rationale: a fixed type keeps the child-mapping tree singular and reuses the existing single-tree `fields`/`nativeFields` shape verbatim; a discriminator needs N child trees keyed by type and per-row type resolution — materially more surface for little near-term value. A feed that genuinely interleaves block types is rare; those users can add a second Matrix mapping row per type in a later iteration.
- **D2 — Sync semantics: FULL REPLACE for v1.** On every sync the field's blocks are rebuilt from the feed (all fresh `newN` keys). Rationale: (a) merge requires stable per-block identity across syncs, which feeds rarely provide and which our mapping shape has no slot for; (b) full-replace is what "the feed is authoritative" (the plugin's stated empty policy) implies at the collection level; (c) it's the only semantic that's correct without a block-level match key. Per-block merge is a follow-up gated on a block-identity node.
- **D3 — Block ordering: feed order.** The order blocks appear in the resolved sub-array is the order they're written (guaranteed by Craft's `array_keys($value)` sortOrder for the flat shape). No sort option in v1.
- **D4 — hasChanged/valueDiffers: cheap structural fingerprint.** Compare a normalized fingerprint of the incoming blocks (ordered list of `[type, <resolved child field values>]`) against a fingerprint of the current blocks (ordered `[type, <serialized field values>]` read from the block query). Full-replace means a false "changed" only costs a redundant save; a false "unchanged" would drop a real edit, so the guard errs toward changed (an unreadable current value → changed, inherited from `Field::hasChanged()`).
- **D5 — FeedMe converter: FOLLOW-UP, not this iteration.** Keep dropping Matrix `blocks` mappings with the existing warning. Rationale: Feed Me's `blocks` shape (per-block-type sub-`fields` keyed by block-type handle, with its own node conventions) is a distinct mapping problem from building our shape; converting it well needs the Matrix data model to exist and settle first. Ship Matrix, then convert.

## v1 non-goals (explicit)

- Multiple block types per Matrix mapping row (discriminator node). [D1]
- Per-block merge / block-level identity matching. [D2]
- Block reordering options / sort-by-node. [D3]
- Nested Matrix-in-Matrix beyond the existing `MAX_DEPTH = 3` cap (allowed if it fits the cap, not specifically designed for).
- Feed Me `blocks` conversion. [D5]
- Setting `enabled`/`collapsed`/`title`/`slug` per block from the feed — v1 writes blocks `enabled: true`, title/slug left to the block type's own generation. (Native block sub-fields are a small follow-up via the existing `nativeFields` channel if the block type exposes a title field.)

---

## Data model — stored mappings JSON shape (AMENDED at implementation)

> **Amendment (2026-07-02, implemented):** the Matrix row itself carries **no
> `node`** — child mappings each carry their own **absolute** item path, exactly
> like relational sub-mappings (which have always resolved against the top-level
> item). Blocks are derived by index-zipping the per-child value lists that
> `RemoteItem::get()`'s collapsed-list fan-out produces. Only subfields with an
> actual mapping (`FieldMapping::isActive()`) are written.

```
mappings[<matrixHandle>] = {
    options: {
        blockType: 'season'              // FIXED block-type handle for every block [D1]
    },
    fields: {                            // child custom-field mappings — ABSOLUTE paths
        year:  { node: 'seasons.year' },
        notes: { node: 'seasons.summary' }
    },
    nativeFields: {                      // optional; e.g. the block title
        title: { node: 'seasons.label' }
    }
}
```

- **No source node on the Matrix row.** The row's value derives entirely from its
  sub-mappings; a new strategy-level `Field::addressed()` hook lets the node-less
  row still pass `MappingApplier`'s addressed gate (Matrix: addressed when any
  active child addresses the item).
- **Per-block values via collapsed-list fan-out:** `seasons.year` resolves to the
  list of every season's year; block count = the longest contributing child list;
  a per-index missing value leaves that key absent on that block. Child values are
  coerced through their own field strategies (valueMap/truthy/format/match all
  apply) via a synthetic single-value `RemoteItem` and `FieldContext::descend()`
  (which gained an optional item override).
- **Alignment caveat:** the fan-out drops null elements (dense lists), so child
  alignment relies on uniform block arrays in the feed. And an array-valued child
  node for a SINGLE block is indistinguishable from per-block values (documented
  v1 limitation on the Matrix class) — v1 targets scalar-per-block child nodes.
- **Block type** (`options.blockType`): the fixed handle, resolved via Compat.
- No stored-data BC concern (pre-release); additive within the existing recursive
  schema.

---

## Feature: Matrix strategy (PHP)

- [ ] **Step 1: Compat block-type discovery** — add version-branched introspection to `src/helpers/Compat.php`. **small**
  - New methods (both branch on existing `Compat::isCraft5()`; no PHP 8.2+ syntax; no `final`/`readonly`/`private`):
    - `matrixBlockTypes(craft\base\FieldInterface $field): array` → returns a normalized `list<array{handle: string, name: string, layout: ?craft\models\FieldLayout}>`. Craft 5: iterate `$field->getEntryTypes()`, each `->handle`, `->name`, `->getFieldLayout()`. Craft 4: iterate `$field->getBlockTypes()`, each `->handle`, `->name`, `->getFieldLayout()`.
    - `matrixBlockTypeIdByHandle(craft\base\FieldInterface $field, string $handle): ?int` → Craft 5: find the `EntryType` whose handle matches, return `->id`. Craft 4: find the `MatrixBlockType`, return `->id`. (Used only if the flat serialized shape ever needs an id — for the flat `type => handle` path it does not; keep it thin or drop if unused after Step 2.)
  - Use `method_exists($field, 'getEntryTypes')` as the feature marker rather than `isCraft5()` where practical, matching the file's existing feature-detection idiom.
  - Gate: `composer check-cs` clean on the file; a scratch `php -r` (or a boot test in Step 4) resolving `matrixBlockTypes()` against a real Matrix field returns the expected handles.

- [ ] **Step 2: Matrix strategy `parse()` + change detection** — rewrite `src/fields/Matrix.php` from placeholder to a real strategy. **large**
  - Extends `Field` directly (NOT `DefaultField`, NOT `RelationalField` — it neither writes ids nor persists sub-elements; it builds a serialized value).
  - `parse(FieldContext $context)`:
    1. Resolve the sub-array via `$context->mapping->resolve($context->item)`; null → return null (clears the field, consistent with the empty policy). Re-wrap a single/scalar result into a list (borrow `referenceValues()` logic — either duplicate the tiny helper or lift it to `Field`).
    2. Read `options.blockType`; if it doesn't match any `Compat::matrixBlockTypes($context->craftField)` handle, throw (present-but-misconfigured → per-row error, per the `parse()` contract).
    3. For each block element, build a **per-block `RemoteItem`** (`new RemoteItem((array) $blockElement)`), then harvest child field values. **Do not** use `applySubMappings()` (it mutates+signals a live element). Instead, add a sibling walk that RETURNS a `fields` array: for each `$context->mapping->subMappings()` entry, resolve via that sub-mapping's strategy against a `descend()`-derived context whose `$element` is a throwaway in-memory block element of the right type — OR, simpler and preferred: resolve each child mapping's value directly through `Influx::getInstance()->fields->forCraftField($childCraftField)->parse($childContext)` and collect into `['fields' => [$handle => $value]]`. The child `FieldContext` is derived with `$context->descend($blockElement, $subMapping, $childCraftField)` so the depth cap and dry-run flag carry (see Step 3 for the descend-target detail).
    4. Native sub-fields (`nativeFields`, e.g. `title`) resolved the same way and placed at the block-row top level (`['title' => ..., 'fields' => [...]]`).
    5. Assemble `[$key => ['type' => $blockTypeHandle, 'enabled' => true, 'fields' => [...], (+title)]]` with sequential `new1`, `new2`, … keys. Return that array. Empty list → return `[]` (explicit clear) not null, so the field is emptied (mirrors relational `apply()` coercing null→[]).
  - `apply()`: inherit the base (`setFieldValue($handle, $value)`). Blocks persist on the owner save in `ItemProcessor::commit()` — no override needed. **Verify EntryTarget needs zero changes** (it does — the owner save already flows through `saveElement`, which triggers `NestedElementManager`).
  - `valueDiffers()` [D4]: build `fingerprint($blocks)` for incoming (ordered `[type, ksort'd resolved child values]`) and for current (read the block query via `$current->all()`, each block's `->getType()->handle` + `getSerializedFieldValues()`); compare `json_encode`. Wrap current-read in the base try (inherited) so a failing block query → assume changed.
  - `defineExtrasSchema()`: see Builder section (Step 4). Remove the "not yet supported" note.
  - **Dry-run:** `parse()` builds an in-memory serialized array and creates NO elements and saves NOTHING, so it is inherently dry-run-safe. Blocks are only ever persisted by the owner save, which `ItemProcessor::commit()` already skips under `dryRun`. Confirm no code path in the child-value harvest creates/saves (e.g. a child Assets mapping with `upload` — that already honors `$context->dryRun`, which `descend()` propagates).
  - Register: already in `FieldsService::defaultFields()` (Matrix::class present) — no registry change.
  - **Unit tests (no Craft boot)** in `tests/unit/fields/MatrixFieldTest.php`, mirroring `RelationalFieldTest`'s mock-element pattern:
    - single block-row sub-array → serialized shape with one `newN` block, correct `type`, child `fields` populated;
    - multi-element sub-array → N blocks in feed order (assert key order + count);
    - empty/absent sub-array → `[]` (clear);
    - unknown `blockType` → throws;
    - fingerprint stability: same input twice → `valueDiffers()` false against a stubbed current with identical serialized values.
    - Child-mapping resolution is exercisable by stubbing `FieldsService::forCraftField` to a fake strategy (the class walks the registry the same way `MappingApplier` does).
  - Gate: `composer exec codecept run unit -- --filter=MatrixFieldTest` green (run under the PHP 8.4 binary per project ECS/codecept convention).

- [ ] **Step 3: child-context descend target** — resolve the "descend into what element" question for block child mappings. **small**
  - Problem: `FieldContext::descend($subElement, ...)` expects a real element whose layout holds the child field. For a Matrix block we need the child field's `craft\base\FieldInterface` instance (to pick the strategy) and an element context for strategies that read it (RichText's `serializeValue`, relational lookups). Build a throwaway block element of the target type (Craft 5: `new craft\elements\Entry(['typeId' => ...])`; Craft 4: `new craft\elements\MatrixBlock(['typeId' => ...])`) via a Compat helper `newMatrixBlock(FieldInterface $matrixField, string $typeHandle): ElementInterface`, get its field layout, look up each child field by handle, and `descend()` onto it.
  - The child strategy `parse()` returns the value; the Matrix strategy collects it into the block's `fields` array (it does NOT call the child's `apply()` — the value is serialized data, not a set-on-element write). This is the crucial divergence from `RelationalField`.
  - Depth: reuse `descend()`'s `MAX_DEPTH` guard verbatim — a Matrix inside a Matrix child still throws `MappingDepthException` past depth 3.
  - Gate: covered by Step 2's tests (child-field resolution + depth); no separate gate.

**Manual checks (this feature) — required:**
- On **Craft 5** (this install): create a link mapping a JSON sub-array to a real Matrix field with 2+ child fields; run a real sync; open the created/updated entry in the CP and confirm the correct number of blocks, correct block type, child field values populated, blocks in feed order.
- Re-run the same sync unchanged → item logs as UNCHANGED (fingerprint holds), no redundant block churn.
- Change one child value in the feed sample and re-sync → blocks fully replaced with new values.
- Clear the feed sub-array (`[]`) and re-sync → all blocks removed.
- Dry-run via the debug inspector on a Matrix mapping → report shows the parsed block structure and creates/saves NOTHING (verify no stray blocks in DB, no new nested entries).

**Manual checks (this feature) — required, second version:**
- Repeat the create/update/reorder/clear cycle on a **Craft 4** install (Matrix = `MatrixBlock` + `MatrixBlockType`). This is the highest-risk surface — Compat block-type discovery and the serialized-value acceptance are the two things that can silently differ. Flag any divergence back into `Compat`.

---

## Feature: Builder UI

- [ ] **Step 1: `mappableFields` exposes block types + child field layouts** — extend the Matrix branch of the fields payload. **medium**
  - `EntryTarget::getMappableFields()` already emits per custom field `fieldMeta => Influx::getInstance()->fields->metaFor($field)`. For a Matrix field, `metaFor` calls `Matrix::defineExtrasSchema($field)` — so the block-type picker + child rows are declared THERE, keeping the Vue side generic (no new payload plumbing in `LinkBuilderService`).
  - `Matrix::defineExtrasSchema(CraftFieldInterface $field)` returns:
    - `BuilderSchema::select('blockType', 'Block type', <options from Compat::matrixBlockTypes()>, ['default' => <first handle>])` — one option per block type (`value` = handle, `label` = name).
    - For EACH block type, a child-mapping editor. The existing `BuilderSchema::elementSubFields()` only covers native sub-fields and writes the `nativeFields` channel. Matrix needs child **custom** fields written to the `fields` channel, with `showIf: [{handle: 'blockType', equals: <thisHandle>}]` so only the selected type's fields render.
  - **New BuilderSchema node type** `TYPE_MATRIX_FIELDS = 'matrixFields'` (factory `BuilderSchema::matrixFields(string $label, array $childFields, array $config)`), writing the mapping's `fields` channel (parallel to how `elementSubFields` targets `nativeFields`). Each child field contributes a primitive node (built from that child field's own `metaFor`/default type — reuse `EntryTarget`'s per-field node-building logic, extracted to a shared helper so a block's field layout produces the same row shape as a top-level field).
  - Because D1 fixes one block type per row, in v1 render only the SELECTED block type's child fields (via `showIf` on `blockType`), so the payload can carry all block types' child-field node lists but only one shows.
  - Gate: a boot-capable test (or manual) asserting `metaFor(<matrix field>)['schema']` contains a `blockType` select with the field's block-type handles and a `matrixFields` node per type.

- [ ] **Step 2: Vue renders block-type picker + nested child rows** — `SchemaForm.vue` + a new `MatrixFields.vue` input. **large**
  - `SchemaForm.vue` already splits `optionNodes` (rendered inline) from `subFieldNodes` (`type === 'elementSubFields'`, rendered as nested `ElementSubFields` cards writing `nativeFields`). Add a parallel split for `type === 'matrixFields'` rendered by a new `schema/inputs/MatrixFields.vue`.
  - `MatrixFields.vue` generalizes `ElementSubFields.vue`: same `MappingGroupCard` chrome, same source-node select + default per row, same missing-node detection, same `__default__` sentinel handling. The ONE difference: it emits `update:fields` (writing the mapping's `fields` channel) instead of `update:modelValue`→`nativeFields`. Each child row's node path is documented (in the card hint) as **relative to the block sub-array element**.
  - `MappingRow.vue` currently forwards `@update:options` and `@update:nativeFields`. Add `@update:fields` → `writeMapping('fields', ...)` (the `setMappingSlot` pruning already handles the `fields` key generically — verify `lib/mappings.js` prunes nested empties; it prunes empty objects, so an all-empty `fields` collapses correctly).
  - The `blockType` select is a plain option node — rendered by the existing `optionNodes` path with zero new code. Its `showIf` gates which `matrixFields` card shows.
  - JS tests (Vitest, mirroring `schema/__tests__/SchemaForm.test.js` and the mappings lib tests): `matrixFields` node routes to the new component; editing a child row writes the `fields` channel with correct pruning; switching `blockType` shows the matching card.
  - Gate: `npm test` (Vitest) green for the new specs. Build with `npm run build` (or `npm run dev` for watch mode — it runs `vite build --watch`; the CP only sees compiled `dist/`).

- [ ] **Step 3: `translatableStrings` + i18n** — add any new Vue-wrapped strings to `LinkBuilderService::translatableStrings()`. **small**
  - Strings: "Block type", the child-fields card heading/hint ("Node paths are relative to each block in the source array"), any new pills. Server-built option labels already route through `Craft::t()`.
  - Gate: grep the new Vue files for `$t(` and confirm each literal is present in `translatableStrings()`.

**Manual checks (this feature) — required:**
- In the builder, add a Matrix field mapping row: pick the source sub-array node, pick a block type, map 2+ child fields to nodes. Save. Reload the link and confirm the block-type selection and child mappings re-hydrate correctly.
- Confirm the child-field rows only show for the selected block type; switching block type swaps the visible child rows.
- Confirm the "Fetch sample" node dropdowns populate the child rows' source-node selects (block child nodes are relative — verify the hint communicates this and that a mapped child node resolves at sync time against the block sub-array).

**Manual checks (this feature) — optional:**
- Read-only mode (allowAdminChanges off): the Matrix extras render disabled, no writes.
- A Matrix field whose block type has NO custom fields: the row shows the block-type picker and an empty (or hint-only) child card without erroring.

---

## Feature: Regression safety net

- [ ] **Step 1: full unit suite** — run the whole no-boot suite after PHP changes land. **small**
  - Gate: `composer exec codecept run unit` green (PHP 8.4 binary).
- [ ] **Step 2: full JS suite** — run Vitest across the builder. **small**
  - Gate: `npm test` green.
- [ ] **Step 3: ECS** — house style on all touched PHP. **small**
  - Gate: `composer check-cs` clean (note house deviations: `! ` spacing, blank-line-before-statement, `=>` alignment — already in the ECS config).

---

## Ordered milestones

> Status: **M1 and M2 implemented** (2026-07-02, with the amended node-less data
> model). **M3 — the manual matrix on Craft 5 AND Craft 4 — is still owed** and
> remains the sign-off gate; the Craft 4 pass is the highest-risk surface.

1. **M1 — Compat + strategy core (PHP).** Matrix strategy feature Steps 1–3 + regression Step 1/3. Ship the parse/change-detection engine with full no-boot coverage. *Rough size: 1 focused session for Compat+parse, a second for descend-target + tests.* This is the load-bearing milestone; the builder is inert-safe (still shows the old note) until M2 lands, but you can hand-craft a mapping in Project Config to smoke-test the engine.
2. **M2 — Builder UI.** Builder feature Steps 1–3 + regression Step 2. Wire the block-type picker and child-field editor. *Rough size: one session PHP schema, one session Vue.*
3. **M3 — Manual matrix, both versions.** Run the required manual checks on Craft 5 (this install) and Craft 4. Fold any divergence into Compat. *Rough size: half a session per version.*
4. **M4 (follow-up, separate iteration) — Feed Me `blocks` conversion** [D5] and **discriminator/multi-type** [D1] / **per-block merge** [D2]. Not in this plan's scope.

---

## Top risks + mitigations

1. **Craft 4 serialized-value shape or block-type API differs from the Craft-5-verified assumptions.** The vendor tree here is Craft 5 only, so the Craft 4 serialized-value acceptance and `getBlockTypes()`/`MatrixBlockType::getFieldLayout()` paths are docs-knowledge, not code-verified. *Mitigation:* isolate ALL divergence behind the two new Compat methods (block-type discovery + block-element construction) and the value-shape assembly; keep `parse()` free of any `craft\elements\MatrixBlock`/`Entry` hard reference (construct blocks only through Compat). Make M3's Craft 4 pass a hard gate before considering the feature done — treat a Craft 4 failure as "extend Compat", never "patch the strategy with a version check outside Compat".
2. **(Superseded by the amendment.)** Child paths are now ABSOLUTE — the same base as every other mapping in the builder, so the relative-path confusion risk is gone. The trade-offs it was exchanged for: dense-list alignment (nulls collapse away, so ragged feeds can misalign children) and the array-valued single-block ambiguity — both documented on the Matrix class and covered by ragged-list unit tests.
3. **Full-replace churn or false-changed causes needless block re-creation every sync** (deleting+recreating nested entries thrashes the DB and bloats revisions). *Mitigation:* the [D4] fingerprint compares resolved child values against current serialized values so an unchanged feed yields UNCHANGED and `ItemProcessor::commit()` skips the save entirely (no owner save → no `NestedElementManager` run → no block churn). Verify the fingerprint is order-stable (ksort child values) and that a re-run logs UNCHANGED in the required manual check.

---

## Verified vs. flagged

- **Verified against Craft 5.10.5 vendor code:** flat serialized shape acceptance, feed-order preservation, new-block `type`-handle requirement, `fields` applied via `setFieldValue`, blocks persisted by owner save (`normalizeValue`/`_createEntriesFromSerializedData`/`serializeValue` in `vendor/craftcms/cms/src/fields/Matrix.php`), `getEntryTypes(): EntryType[]` and `EntryType::getFieldLayout()`.
- **Flagged (docs-knowledge, unverifiable in this tree):** Craft 4 `MatrixBlock`/`MatrixBlockType` classes, `getBlockTypes()`, `MatrixBlockType::getFieldLayout()`, and that Craft 4 accepts the same `[$key => ['type' => handle, 'enabled' => .., 'fields' => [..]]]` `setFieldValue` shape. These MUST be confirmed on a Craft 4 install in M3 before the feature is signed off.
