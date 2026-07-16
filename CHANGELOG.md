# Release Notes for Influx

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
