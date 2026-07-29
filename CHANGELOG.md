# Release Notes for Influx

## 1.0.0-alpha.6 - 2026-07-29

### Changed
- Queue job descriptions read as sentences naming the link, the sliding-window preset and the site — "Importing Units with 1day for site Durabrik - NL" — instead of a handle with `(site: …, preset: …)` appended. Each clause is part of a translatable sentence now rather than concatenated outside the translation, and descriptions and progress labels are lazy-translated (`Translation::prep()`), so a queue row reads in the viewer's own language rather than the language of whoever triggered the run.

### Fixed
- **Progress never moved:** a queued sync reported progress once per feed page, but a queued run walks one page per job — so the only report landed as the job was about to finish and the bar sat at 0% for the whole of every step (an unpaginated feed is a single job, so the entire run showed 0%). Progress is reported per item now, throttled to whole-percent movements, and its cumulative count is carried across steps so a re-queued page resumes the percentage instead of restarting. It also no longer depends on `loggingEnabled`, which pinned the numerator at 0 when logging was off.
- A run log's "seen" counter counted log ROWS, not feed items: it also counted every element the missing-elements sweep disabled or deleted (elements that by definition weren't in the feed), sweep-bail notices, and an item that logged twice. It counts the items the feed actually contained now; swept elements remain in the "disabled" / "deleted" counters.

### Added
- **Field indicator:** a small Influx glyph now marks every field an active mapping writes, on the edit screen of any targeted element. Its tooltip explains the value is set by synchronisation, so an editor sees at a glance which values are Influx-managed and may be overwritten on the next sync.
- Extension points can be registered imperatively as well as by event: `Influx::getInstance()->targets->register(MyTarget::class)`, likewise `->fields->register()` and `->auth->register()`. Registering a class that doesn't satisfy the extension point now fails loudly instead of being dropped.
- `SchemaBuilder::node()`, an escape hatch for a mapping-extras control the builder doesn't ship a node type for. The CP renders an unrecognised type as a labeled text input on the node's handle rather than dropping it.
- The endpoint-token allow-list is overridable, so a plugin can substitute tokens for field types Influx doesn't allow by default.
- Enum owners for the run-status, item-action and missing-element-processing vocabularies (`RunStatus`, `ItemAction`, `ProcessingAction`, `RunFailure`), plus a `schema` namespace holding `SchemaBuilder` and a typed `MappableField` descriptor.
- The CP's action colours and log counters are derived from those enums and shipped to the Vue apps in each app's bootstrap config, instead of being hand-maintained in the JavaScript.
- `ElementTargetInterface::supportsSweeping()` — a third static capability beside `supportsMultiSite()` and `criteriaKeys()`: whether links to this element type can be swept for elements missing from the feed. Targets extending `AbstractElementTarget` need no change; one implementing the interface directly must add it.
- `ElementTargetInterface::save()` — the engine's element write routes through the target now, like the four destructive writes already did, so a third-party target can save with whatever flags its element type needs. The base implementation is Craft's own save with validation off (the feed is authoritative — the same policy Feed Me imports run on).
- `ElementTargetInterface::claimCells()` — a target describes how its element type partitions, so a custom element type reports a real claim scope instead of the "everything" sentinel that made any two links of that type warn as overlapping. Entry's section × entry-type expansion moved from `Link` onto `EntryTarget`.
- `Link::criterion($key)`, the one reader for stored `elementCriteria`, with the key names owned as constants by the target that declares them (`EntryTarget::CRITERIA_SECTION` / `::CRITERIA_TYPE`). Stored config is untouched.

### Changed
- **Breaking:** the endpoint-token events follow Craft's `EVENT_DEFINE_*` convention. `EndpointTokensService::EVENT_REGISTER_ENDPOINT_TOKENS` → `EVENT_DEFINE_ENDPOINT_TOKENS` and `EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS` → `EVENT_DEFINE_ENDPOINT_TOKEN_SUGGESTIONS`; the payload classes `RegisterEndpointTokensEvent` and `RegisterEndpointTokenSuggestionsEvent` are now `DefineEndpointTokensEvent` and `DefineEndpointTokenSuggestionsEvent`.
- **Breaking:** the targets, fields and auth registries share one API. `FieldsService::registerClass()` is now `register()`, and `AuthService::strategies()` is now `all()`.
- **Breaking:** `SchemaBuilder` moved from `GlueAgency\Influx\helpers` to `GlueAgency\Influx\schema`.
- **Breaking:** `ElementTargetInterface::getMappableFields()` returns a list of `schema\MappableField` objects instead of plain arrays.
- **Breaking:** `DebugService::streamSite()` is now `inspectSite()`; inspecting a stored log item moved to `InspectorService::inspectStoredLogItem()`.
- **Breaking:** the sync engine's collaborators moved into `sync\item` (the per-item pipeline) and `sync\run` (per-run orchestration). `SynchronizationService`'s own public methods and all five sync events — constants, sender, firing order and cancel semantics — are unchanged.
- Every JSON route answers a failure with the same envelope: `{success: false, message}`, plus `type` (the exception's short class name) for an uncaught error or `errors` for a validation failure.
- Queuing a sync for a link that doesn't take a pre-run backup no longer enqueues an extra job to take one.
- `Link::claimScope()` delegates its cells to the link's target; the model no longer branches on `craft\elements\Entry` or reads project config itself.
- Field strategies and the mapping walk resolve strategies (and their sub-mapping walk) through the `FieldContext` they're handed instead of reaching the plugin singleton mid-walk, which also removes the applier ↔ field-registry construction cycle. `MappingApplier` takes an optional strategy-resolver callable, `Matrix::childStrategy()` takes the context as its first argument, and `InspectorService` accepts an injected `ItemProcessor`.

### Removed
- **Breaking:** `Link::PROCESSING_*`, `Link::ALL_PROCESSING` and `Link::PROCESSING_SITE_COUNTERPARTS`. The `ProcessingAction` enum owns the processing flags, and their defaults, per-site counterparts, labels and pill colours all derive from it.
- The `pending` run status, which was never written. A run is `running`, `ok` or `error`.

### Fixed
- **Data loss:** queuing a sync for a single-site link enqueued an unscoped job, which fetched the wrong feed and could sweep missing elements across every site instead of the link's own. Site expansion now happens up front.
- **Silent no-op:** a User link's builder offered "Disable missing" / "Delete missing", but users can't be swept (no scope to enumerate) — the run did nothing and reported nothing. Those checkboxes are no longer offered for an element type that can't sweep, and a link still carrying one from older config gets a skipped row in the log explaining why.
- A related element that refuses to save — a category, tag or entry the feed also fills, rejected by validation — no longer passes silently: the mapping row now errors with the element's id, title and validation messages, so the log drill-down shows why the related values didn't change. Previously the save's result was discarded and the parent row reported success over stale values.
- URL-mode asset matching now really prefers the asset whose URL matches the remote one exactly. The preference was unreachable — the lookup had already collapsed to a single row — so a same-filename asset from another folder could be linked while the exact match sat one row away. A same-filename asset is still the best-effort fallback when no URL matches (a CDN/host change doesn't force a re-download), and a volume that exposes no URLs no longer aborts the comparison.
- A failed save logged `[]` as its reason. Syncs save with validation off by design, so the element's error bag is normally empty on failure; the row now says Craft refused the save without validation errors (a `beforeSave`/`afterSave` handler), and still reports real errors when there are any.
- `UserTarget` memoized the user-group handle → id map on the registry's shared prototype, so a group created mid-process stayed invisible for the rest of a worker's lifetime. The memo lives on the run's `SyncContext` now, next to the element-lookup cache.
- A long-running queue worker accumulated one dead log-item buffer per log id it ever wrote: flushing emptied the buffer but never dropped its map entry. Buffers are released when a run finishes, fails, or its log is deleted.
- A Matrix field with a lightswitch sub-field counted as changed on every run — the stored `1` / `0` was compared against the feed's `true` / `false` — so the element was re-saved each sync.
- Likewise for a date sub-field: Craft hands a stored date back serialized as a string while the feed's is a `DateTime`, so the two never matched. Both sides of a sub-field comparison now normalise through the strategy that owns that field type, so the same instant compares equal whichever shape it arrives in.
- A queued run's progress denominator shifted mid-run: the queued page loop sized its estimate from the current page while the synchronous one used the first page, so a short final page changed the reported total. Both use the first page's size now.
- "Disabled for site" rows in the run log and debug inspector render red, like their deleted counterpart.
- Link-builder strings missing from the server-side catalogue now translate, and stale entries are gone. The catalogue is pinned to the Vue sources by a test, so it can't drift again.
- The run-log viewer and debug inspector were untranslatable: they install the same translation helper as the link builder, but no catalogue of their strings was ever registered, so every label they render themselves stayed English on a translated control panel. Both apps — and the components they share — now ship a catalogue of their own, registered by the screen that mounts them and pinned to the Vue sources by the same kind of test. The log viewer's status filter also names the active counter with the label the server already translated, instead of the raw action value.
- The nav and plugin-store icons render in full again. The previous mark was stroke-based and Craft's icon-mask rendering zeroed its strokes, collapsing it to an underscore.

## 1.0.0-alpha.4 - 2026-07-16

### Fixed
- **Authenticated pagination:** a paginated sync against a token-protected API failed on the second page — feed-supplied next-page URLs were fetched without the link's credentials, so the gateway dropped the request. The link's auth is now re-applied to every same-origin page. A next-page URL pointing at a different host is still fetched unauthenticated, so the token never leaks off-origin.

## 1.0.0-alpha.3 - 2026-07-16

### Added
- Plugin settings are now reachable from the CMS **Settings → Plugins** list, not just the Influx nav dropdown — both open the same settings screen.
- Elements targeted by more than one link can be synced per link from the "Sync from remote" control.

### Changed
- New plugin icon.
- CP-triggered syncs take their pre-run DB backup inside the queue job now, so the request returns immediately instead of blocking on the dump.
- Field-type schema definitions build their mapping UI through a fluent `SchemaBuilder`, and the link builder strips switched-off fields from the saved config.
- Refined the run-log and debug inspector rows.

### Fixed
- **Data loss:** a sliding-window (offset) sync no longer runs the missing-elements sweep. An offset run fetches only part of the feed, so its "missing" set is everything outside the window — deleting or disabling it would wipe content that simply wasn't in the slice. Only a full sync may delete or disable.
- Assorted sync-correctness fixes.

### Security
- Hardened the sync and backup flow.

## 1.0.0-alpha.2 - 2026-07-13

### Added
- `UserTarget`: links can now hydrate `craft\elements\User` (username, email, full/first/last name, enabled, and custom user fields), matchable by id / username / email / a custom field.
- User links can assign group membership via a `groups` mapping field: pick the groups plus `update` (also apply to existing users) and `remove` (make the selection authoritative) toggles. Membership is reconciled through the Users service after each item commits.
- Element targets now declare `supportsMultiSite()` and `criteriaKeys()`, and gain an `afterCommit()` hook for state that lives outside the element save. Non-multi-site targets (Users) run once globally; the builder hides the site-specific endpoint and section/type controls that don't apply, and `Link` rejects site endpoints configured against them.
- Logs overview: a **Site** column, and clickable per-action counters that filter the run list (the active filter is kept in the URL).

### Changed
- Redesigned the control-panel screens: the Links and Logs overviews now use Craft's native tables with a shared status-pill vocabulary, and the Debug inspector and run-log viewer are split master/detail views — an item list beside a per-field drill-down with a Parsed / Raw JSON toggle — sharing one field-comparison component. The Debug inspector moved to a standalone `influx/debug?link=<handle>` page with a link switcher.
- Element-triggered "Sync from remote" runs no longer count as a link's "last run" on the overview — that stays the last full-feed run.

### Fixed
- The "Sync from remote" button no longer breaks entry saving: it rendered its own `<form>` inside the entry edit page's main form, and the resulting invalid nesting made the browser close the main form early — losing the `action` input and every field value on save, and disabling autosave drafts. The button now posts to the controller action via Craft's `formsubmit` pattern (a detached temporary form) instead.
- "Sync from remote" on a link with per-site endpoints now syncs — and records on its log — only the element's current site, instead of every site the element has a row in.

## 1.0.0-alpha.1 - 2026-07-03

### Added
- Initial alpha release.
- Links: Project Config-backed sync definitions connecting Craft elements to external JSON APIs, with a CP builder (endpoints, auth, field mappings, per-site endpoints, offset presets).
- Field mapping strategies for plain, relational, option, and Matrix fields, with recursive sub-field mappings.
- Sync pipeline: queued page-per-step runs, per-site logs, missing-elements processing (disable / delete / delete-for-site), single-element "Sync from remote".
- Run logs with per-item drill-down, live updates, and run-context display (site, offset preset, resource).
- Debug inspector for dry-running a link against its feed.
- Feed Me converter console command.
