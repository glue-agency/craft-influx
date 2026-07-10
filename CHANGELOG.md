# Release Notes for Influx

## Unreleased

### Added
- `UserTarget`: links can now hydrate `craft\elements\User` (username, email, full/first/last name, enabled, and custom user fields), matchable by id / username / email / a custom field.
- User links can assign group membership via a `groups` mapping field: pick the groups plus `update` (also apply to existing users) and `remove` (make the selection authoritative) toggles. Membership is reconciled through the Users service after each item commits.
- Element targets now declare `supportsMultiSite()` and `criteriaKeys()`, and gain an `afterCommit()` hook for state that lives outside the element save. Non-multi-site targets (Users) run once globally; the builder hides the site-specific endpoint and section/type controls that don't apply, and `Link` rejects site endpoints configured against them.

## 1.0.0-alpha.1 - 2026-07-03

### Added
- Initial alpha release.
- Links: Project Config-backed sync definitions connecting Craft elements to external JSON APIs, with a CP builder (endpoints, auth, field mappings, per-site endpoints, offset presets).
- Field mapping strategies for plain, relational, option, and Matrix fields, with recursive sub-field mappings.
- Sync pipeline: queued page-per-step runs, per-site logs, missing-elements processing (disable / delete / delete-for-site), single-element "Sync from remote".
- Run logs with per-item drill-down, live updates, and run-context display (site, offset preset, resource).
- Debug inspector for dry-running a link against its feed.
- Feed Me converter console command.
