# Endpoint-shaped delete policies + one queue job per site

Two coupled changes. Change A removes the run-end union-delete machinery by making
`delete` a no-site-endpoints-only, single-pass-global policy. Change B then becomes
trivial: one site-scoped job per site instead of one job walking every site.

Auth level: unchanged. `SynchronizationController` stays permission-gated as-is
(`AbstractController`); console stays as-is.

## Decision points

- **D1 — invalid-flag-on-mode-switch (Change A2):** an already-checked now-invalid flag
  is left checked in the payload; the reactive UI disables the box and shows a hint, and
  server-side `defineRules()` rejects the combo on save. Chosen over auto-unchecking so a
  user toggling the mode switch back and forth doesn't silently lose a flag. (Alternative:
  uncheck in `toggleProcessing`/mode-setter — rejected: destructive + surprising.)
- **D2 — defensive runtime guard:** even though validation forbids `delete` + site
  endpoints, `perSitePolicy()`/the per-pass sweep keeps a guard: a link that somehow has
  site endpoints AND `delete` skips the delete with a logged SKIPPED row + `Craft::warning`,
  rather than deleting cross-site. Cheap insurance against a hand-edited project-config YAML.
- **D3 — console exit code (Change B3):** a single site failing its own log does NOT abort
  the console run or change its exit code (per-site isolation). The console already ignores
  `syncLink()`'s return; `actionIndex` only returns `SOFTWARE` on a thrown non-fetch failure.
  Documented, not changed.

## Risk (top 3)

1. **Silent semantic change for existing multi-site `delete` links.** Any link currently
   configured with site endpoints + `delete` becomes invalid. Per MEMORY (no backward-compat,
   pre-release, re-sync over migrate) this is acceptable — but the guard (D2) must land so a
   stale YAML config doesn't nuke content before the user re-saves. Surface in the release note.
2. **State-shape shrink touches the resumable queue path.** `batchStep` state and `SyncLinkJob`
   props drop 4 keys and the `siteIndex`/cross-site advance. A job serialized under the OLD
   shape (in-flight during deploy) will hydrate missing props to defaults — verify the new
   `execute()`/`batchStep()` tolerate absent `runSeenIds`/`siteIndex` (they should: typed props
   default, `??=` on array reads). Drain the queue before deploy to be safe.
3. **Event contract change.** `EVENT_AFTER_SYNC_LINK` already fires per site-log (Change B is
   partly reflected in docblocks already), but the cleanup-log firing is removed. Any listener
   counting on the run-end cleanup-log after-event (siteHandle null on a multi-site delete link)
   stops seeing it. Low blast radius (no internal listener found), but note it.

## What stays manual

- Builder UX: toggle "Site-specific endpoints" on/off and confirm the global "Delete elements
  missing from the feed" box disables with its hint while "Delete the site-specific row only"
  enables, and vice-versa; confirm a pre-checked invalid flag shows disabled + the save 400s
  with the field error.
- End-to-end multi-site queue run: trigger an all-sites sync on a link with 2 site endpoints,
  confirm 2 jobs appear in the queue (each named with its site), each finishes its own log,
  and per-site disable/delete-for-site sweeps land in the right site's log.
- Single-site-of-multi run + no-site-endpoints `delete` run: confirm the per-pass global delete
  fires exactly once and only for the no-site-endpoints link.
- Log viewer: confirm no orphan "cleanup" (siteHandle null) logs appear on multi-site delete
  links anymore.

---

## Feature: Change A — gate delete policies by endpoint shape

- [ ] **A1: Model validation + tests** — add a `validateProcessing` validator to
  `src/models/Link.php` `defineRules()` (mirror the `validateMatch`/`validateAuth` style at
  Link.php:241-286; register it like the existing `[['match'], 'validateMatch']` rule at
  Link.php:235). Rule: if `siteEndpoints` non-empty → `PROCESSING_DELETE` is invalid (message:
  `'Global delete isn’t allowed with site-specific endpoints — use “delete the site-specific row only”.'`);
  if `siteEndpoints` empty → `PROCESSING_DELETE_FOR_SITE` is invalid (message:
  `'“Delete the site-specific row only” needs site-specific endpoints — use plain delete instead.'`).
  Keep the existing `each`/`in` range rule (Link.php:237). Extend
  `tests/unit/models/LinkBuilderPayloadTest.php` or add a focused model test asserting both
  reject/accept matrices (no Craft boot needed — `defineRules` + `validate()` on a bare model,
  but `validateProcessing` must not touch `authService()`).
  - Layers: model, test
  - Gate: `php8.4 vendor/bin/codecept run unit --filter=Link` green (rejects `delete`+site,
    rejects `delete-for-site`-without-site, accepts the two valid combos)
  - Complexity: small

- [ ] **A2: Builder reactive gating + strings** — in
  `src/web/assets/links/src/builder/tabs/GeneralTab.vue` (processing checkboxes render here at
  lines 130-142, NOT SettingsTab): add a `computed` that, given `siteEndpointsMode`
  (already wired via the store getter at GeneralTab.vue:182-185), returns per-option
  `{ disabled, hint }` — `delete` disabled when `siteEndpointsMode` is true, `delete-for-site`
  disabled when false. Bind `:disabled` on the `<input>` and render the hint. Leave an
  already-checked now-invalid flag in `link.processing` (D1) — do not mutate it in
  `toggleProcessing` (GeneralTab.vue:249-253) or `setSiteEndpointsMode` (store.js:297-300);
  server validation (A1) is the backstop. Add the two hint strings to
  `LinkBuilderService::translatableStrings()` (src/services/LinkBuilderService.php:338-437,
  under a `// GeneralTab.vue` block) and wrap them in `$t(...)`. Update the
  `processingActionOptions()` labels only if wording needs to align (LinkBuilderService.php:549-558).
  - Layers: Vue component, translatable strings (service)
  - Gate: `cd src/web/assets/links && npm run build` succeeds; existing vitest suite
    (`npm run test` if present) green — else rely on manual check below
  - Manual (required): toggle the mode switch, confirm the correct delete box disables with
    hint and the other enables; a pre-checked invalid flag shows disabled and the save 400s.
  - Complexity: medium

- [ ] **A3: Collapse the sweep to a single per-pass policy + rework tests** — in
  `src/services/SynchronizationService.php`:
    - Delete `sweepMissingForRun()` (lines 894-970), `runCleanupLog()` (196-218), and
      `runPolicy()` (777-782).
    - Fold `DELETED` into `perSitePolicy()` (748-759): precedence `delete` > `delete-for-site`
      > `disable` within one pass. Add the D2 guard: if the link has site endpoints AND the
      resolved policy is `DELETED`, return null-and-log (or route the skip in the sweep) so a
      global delete never fires on a site-scoped/site-endpoint link.
    - Simplify `sweepMissing()` (813-852): it now handles `DELETED` too. For a no-site-endpoints
      link the pass runs once (the single `[null]` scope from `syncSiteHandles()`) so
      `context->siteId` is null and `applyMissingAction` (1060-1072) routes `DELETED` →
      `target->delete()` and `DISABLED` → `target->disable()` (the global branch). Keep the
      clean-pass guard (826-839) and the `delete-for-site`-needs-a-site guard (842-849). Add the
      D2 guard row (site endpoints + delete → SKIPPED via `logSweepSkip` + `warnSweepSkipped`).
    - `applyMissingAction` needs no change — it already dispatches all three actions by policy
      and site scope (1064-1071).
    - Verify nothing still needs `disable`+`delete` COMPOSED: with `delete` now
      no-site-endpoints-only + single-pass, composition is gone. `disable`+`delete` on a
      no-site-endpoints link now resolves to `delete` alone (precedence) in one pass — confirm
      that's the intended single-pass semantics (a global delete supersedes disable; no reason
      to also disable elements you're about to delete).
    - Rework tests: `tests/unit/sync/MissingPolicyTest.php` — drop `runPolicy` assertions and the
      compose cases; rewrite as single-policy precedence (`delete` > `delete-for-site` > `disable`,
      null when none). `tests/unit/sync/MissingSweepRoutingTest.php` — delete all
      `sweepMissingForRun`/`publicSweepMissingForRun` cases and the compose cases; keep/adapt the
      per-site routing cases; add a case for `delete` on a no-site-endpoints link sweeping once
      unscoped, and a D2-guard case (site endpoints + delete → skip). The stub subclass's
      `publicSweepMissingForRun` (275-278) is removed.
  - Layers: service, unit tests
  - Gate: `php8.4 vendor/bin/codecept run unit --filter=Missing` green
  - Complexity: large

- [ ] **A4: Remove run-union accumulation from the resumable path + docs** — in
  `SynchronizationService.php`:
    - `syncLink()` (109-179): remove `$runSeen`, `$runUnattributedErrors`, `$failedSites`,
      `$lastContext`, the `runCleanupLog` tail (170-176), and the `seenIds`/`unattributedErrors`
      union accumulation (156-159). (The per-site loop + one-log-per-site stays — that's Change B3.)
    - `processSite()` (557-622): drop the returned `seenIds`/`unattributedErrors` array (only the
      run-end sweep consumed it); it now just walks pages + fires the per-pass `sweepMissing`.
      Change return type to void (verify caller in `syncLink`).
    - `batchStep()` (269-411): remove `runSeenIds`/`runUnattributedErrors` from state (285-288),
      the `$accumulateRun` gate (321) and its accumulation (342-344, 350-352, 358), and the
      run-end `sweepMissingForRun` call at the last site (401-408). Update the `@param`/`@return`
      state-shape docblocks (265-267) to the shrunk shape (Change B removes `siteIndex` — do
      that in B2, note the coupling). Update the class docblock (26-46) and `sweepMissing` docblock.
    - Do NOT yet touch `SyncLinkJob` run-union props — that's B2, but note both land together.
  - Layers: service, docblocks
  - Gate: `php8.4 vendor/bin/codecept run unit` green (whole suite, confirms nothing else read
    the removed return/state)
  - Complexity: medium

### Change A — manual checks before Change B

- Save a link with site endpoints + `delete` via a hand-edited flow (or just trust A1's test);
  confirm the D2 guard logs a SKIPPED row rather than deleting, if such a config is ever loaded.
- Confirm no `sweepMissingForRun`/`runCleanupLog`/`runPolicy` references remain:
  `grep -rn "sweepMissingForRun\|runCleanupLog\|runPolicy\|runSeen" src tests` returns nothing
  except intentional history.

---

## Feature: Change B — one queue job per site

Depends on A (the state-shape shrink and per-pass-only sweep make this trivial).

- [ ] **B1: Controller dispatches one job per site + message** — in
  `src/controllers/SynchronizationController.php` `actionLink()` (23-56): when `$site === null`
  AND `count($link->siteHandles()) > 1`, push ONE `SyncLinkJob` per site handle (each with
  `'site' => $handle`), instead of one job with `site: null`. Otherwise (single site, or an
  explicit `$site`, or no site endpoints) push one job as today. Message: when fanning out,
  `Craft::t('influx', 'Syncs queued for {n} sites.', ['n' => $count])`; keep the existing
  single-site / scoped messages (52-53). Add the new string to translations if a translations
  file exists.
  - Layers: controller
  - Gate: `php8.4 vendor/bin/codecept run` (controller/functional test if present) green; else
    manual queue check below
  - Manual (required): all-sites trigger on a 2-site link enqueues 2 jobs, each named with its site.
  - Complexity: small

- [ ] **B2: Shrink batchStep to a single scope + drop dead job props/docs** — in
  `SynchronizationService.php` `batchStep()`: remove `siteIndex` from state and the cross-site
  advance (397-408); a job now walks ONE scope's pages, sweeps per-pass when the last page is
  done, then `finishSync()` + `done` — no `runSites()` iteration, no next-site advance. The
  `$requestedSite`/scope comes straight from the job's `site`. Resolve `$siteHandle` directly
  from `$requestedSite` (no `$sites[$state['siteIndex']]`). Document the shrunk state shape:
  `{logId, cursorUrl, page, seenIds, unattributedErrors, done}`. In
  `src/queue/jobs/SyncLinkJob.php`: drop `$siteIndex` (59), `$runSeenIds` (89), `$runUnattributedErrors`
  (96) props and their carry-over in `execute()`/re-push (104-154); update the class + prop
  docblocks (10-96). `defaultDescription()` (158-170) already names the site — no change.
  - Layers: service, queue job, docblocks
  - Gate: `php8.4 vendor/bin/codecept run unit` green
  - Manual (optional): watch a multi-page single-site job resume across steps in the CP queue.
  - Complexity: medium

- [ ] **B3: Synchronous path — one log per site, isolation, event per site + tests** — in
  `SynchronizationService.php`:
    - `syncLink()` (post-A4): loop `runSites($link, $siteHandle)`, each site under its own
      `runWithLog()`-style start/finish (or the inline per-site log already present at 144-168,
      now cleaned of union code). A site whose feed throws `FeedFetchException` fails ITS log and
      the run continues (already the shape at 161-164); a non-fetch throw still propagates. Return
      `list<LogRecord>` (one per site) — verify the only caller, console `actionIndex`
      (src/console/controllers/SyncController.php:78), ignores it (it does; update its comment at
      75-77 to drop the "cleanup log" mention). Confirm D3 exit-code behavior in the console
      docblock.
    - `EVENT_AFTER_SYNC_LINK`: `fireAfterSyncLink()` (225-238) already carries `siteHandle` off
      the log — keep. `EVENT_BEFORE_SYNC_LINK` fires ONCE per run (120-126) — keep. Confirm
      `SyncLinkEvent::$siteHandle` (src/events/SyncLinkEvent.php:31) stays; update the class
      docblock (8-21) to drop the cleanup-log line.
    - `finishSync()` (438-452) is used by the batch path; its after-event has no `siteHandle` —
      set it from `$log->siteHandle` so the batch path matches the synchronous path's event shape.
    - Tests: add/extend a unit test for `syncLink` per-site isolation IF feasible without a full
      Craft boot; otherwise cover via the existing routing test + manual. Update
      `SyncLinkEvent` docblock expectations in any event test.
  - Layers: service, event (docblock), console (comment/docblock), tests
  - Gate: `php8.4 vendor/bin/codecept run` green
  - Manual (required): all-sites run on a 2-site link produces 2 logs each with its `siteHandle`;
    fail one site's endpoint and confirm the other site's log still finishes and the console exit
    code is unaffected (D3).
  - Complexity: medium

- [ ] **B4: Sweep the readers of the old shape** — confirm nothing else assumes one-log-per-run
  or the old state:
    - `LogsService` buffers keyed by log id (LogsService.php:60, 208-211) — fine, per-log buffers
      already; no change.
    - `LogsService::lastRunPerLink()` (220-234) keys by `linkHandle`, keeps newest — with N logs
      per run it now returns the newest SITE log per link. Confirm that's acceptable for the
      index "last run" column (it shows the most recent site's log); if the column must represent
      the whole run, note as a follow-up (out of scope). Read `src/templates/links/index.twig` +
      `src/web/LogPresenter.php` usage to confirm no per-run assumption breaks.
    - Progress callback in `SyncLinkJob::execute()` (119-135) reads a single scope's counts —
      fine post-B2 (one scope per job).
    - `grep -rn "siteIndex\|runSeenIds\|runUnattributedErrors\|sweepMissingForRun\|runCleanupLog\|runPolicy" src tests`
      returns nothing.
  - Layers: read-only audit + one grep gate
  - Gate: the grep returns no live references; `php8.4 vendor/bin/codecept run` green
  - Complexity: small

### Change B — manual checks before shipping

- All-sites sync on a 2-site link: 2 jobs enqueued (each site-named), 2 logs finished, sweeps in
  the right site's log, no orphan siteHandle-null cleanup log.
- No-site-endpoints `delete` link: single job, single log, global delete fires once.
- Failing one site's endpoint isolates to that site's log; console exit code unchanged.
- Links index "last run" column still renders sensibly (shows newest site log).
