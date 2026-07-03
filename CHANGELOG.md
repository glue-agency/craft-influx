# Release Notes for Influx

## 1.0.0-alpha.1 - Unreleased

### Added
- Initial alpha release.
- Links: Project Config-backed sync definitions connecting Craft elements to external JSON APIs, with a CP builder (endpoints, auth, field mappings, per-site endpoints, offset presets).
- Field mapping strategies for plain, relational, option, and Matrix fields, with recursive sub-field mappings.
- Sync pipeline: queued page-per-step runs, per-site logs, missing-elements processing (disable / delete / delete-for-site), single-element "Sync from remote".
- Run logs with per-item drill-down, live updates, and run-context display (site, offset preset, resource).
- Debug inspector for dry-running a link against its feed.
- Feed Me converter console command.
