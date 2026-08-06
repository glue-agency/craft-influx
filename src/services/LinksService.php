<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use DateTime;
use GlueAgency\Influx\db\Table;
use GlueAgency\Influx\events\LinkEvent;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\ElementTargetInterface;

/**
 * Reads and writes Influx links.
 *
 * Architecture (mirrors craft-remote-entries):
 *
 *  - The `influx_links` DB table is the runtime source of truth. All reads
 *    (`getAllLinks`, `getLinkByHandle`, `getLinkByUid`, `getLinkById`) query
 *    the table directly. Nothing in the runtime path touches Project Config.
 *
 *  - Project Config is the *deployment* channel: saving a link writes to
 *    `influx.links.{uid}` in PC, and `handleChangedLink` / `handleDeletedLink`
 *    (wired in {@see \GlueAgency\Influx\Influx::registerProjectConfigEventListeners()})
 *    react to those changes by upserting/deleting the DB row. This means
 *    `project-config/apply` after pulling YAML on a fresh environment seeds
 *    the DB; nothing else needs to run.
 *
 *  - PC rebuild reads `getAllLinks()` (DB) and emits the YAML — same path as
 *    every other rebuild.
 *
 *  - Writes are still gated by `allowAdminChanges` because PC enforces that
 *    constraint on `set` / `remove`.
 */
class LinksService extends Component
{
    public const CONFIG_LINKS_KEY = 'influx.links';

    public const EVENT_BEFORE_SAVE_LINK = 'beforeSaveLink';
    public const EVENT_AFTER_SAVE_LINK = 'afterSaveLink';
    public const EVENT_BEFORE_DELETE_LINK = 'beforeDeleteLink';
    public const EVENT_AFTER_DELETE_LINK = 'afterDeleteLink';

    /** @var Link[]|null in-memory cache keyed by handle */
    protected ?array $links = null;

    /**
     * The query's (sortOrder, name) ordering carries into the handle-keyed
     * array, so it iterates in overview display order.
     *
     * @return Link[] indexed by handle
     */
    public function getAllLinks(): array
    {
        if ($this->links !== null) {
            return $this->links;
        }

        $this->links = [];

        foreach ($this->createQuery()->all() as $row) {
            $link = $this->linkFromRow($row);
            $this->links[$link->handle] = $link;
        }

        return $this->links;
    }

    public function getLinkByHandle(string $handle): ?Link
    {
        $row = $this->createQuery()->where(['handle' => $handle])->one();

        return $row ? $this->linkFromRow($row) : null;
    }

    /**
     * The link with this handle, falling back to the first link in overview
     * order — the resolution rule behind the debug screen, which is one
     * standalone screen scoped by `?link=<handle>` rather than a page per link,
     * so a bare `influx/debug` (or a stale handle) still opens on something.
     * Null only when no links exist at all.
     *
     * Reads {@see getAllLinks()} rather than querying by handle, so both halves
     * of the rule come off the one cached, ordered set.
     */
    public function getLinkByHandleOrFirst(?string $handle): ?Link
    {
        $links = $this->getAllLinks();

        return ($handle !== null ? ($links[$handle] ?? null) : null) ?: (reset($links) ?: null);
    }

    public function getLinkByUid(string $uid): ?Link
    {
        $row = $this->createQuery()->where(['uid' => $uid])->one();

        return $row ? $this->linkFromRow($row) : null;
    }

    public function getLinkById(int $id): ?Link
    {
        $row = $this->createQuery()->where(['id' => $id])->one();

        return $row ? $this->linkFromRow($row) : null;
    }

    /**
     * Every link whose target STRUCTURALLY targets this element — the element
     * is the right type and inside the link's section/type scope — regardless
     * of whether it currently carries a match value. Returned in
     * {@see getAllLinks()} order (sortOrder ASC, name ASC).
     *
     * Structural (not {@see ElementTargetInterface::claimsElement()}) because
     * the callers — the per-entry "Sync from remote" button/menu and the
     * per-element sync endpoint — want to surface / authorize a link even for
     * an element with no match value yet (the button just renders disabled).
     *
     * @return Link[]
     */
    public function findLinksForElement(ElementInterface $element): array
    {
        $links = [];

        foreach ($this->getAllLinks() as $link) {
            $target = $this->targetForLink($link);

            if ($target && $target->targetsElement($link, $element)) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * The element target registered for a link, via the plugin singleton — the
     * one place this service resolves one, and the seam the unit suite stubs
     * (the pure suite never bootstraps the plugin, so the lookup yields null).
     */
    protected function targetForLink(Link $link): ?ElementTargetInterface
    {
        return Influx::getInstance()?->targets?->forLink($link);
    }

    /**
     * The first link that structurally targets this element, or null. A thin
     * convenience over {@see findLinksForElement()} for callers that only need
     * one (e.g. the sync endpoint's no-explicit-link fallback).
     */
    public function findLinkForElement(ElementInterface $element): ?Link
    {
        return $this->findLinksForElement($element)[0] ?? null;
    }

    /**
     * The handles of every field/attribute an active Influx mapping writes on
     * this element (deduped across links), so the element editor can flag each
     * mapped field.
     *
     * "Active" is {@see FieldMapping::isActive()}: a mapping that reads a feed
     * node OR writes an explicit default (a static default like `author` /
     * `enabled` counts — the sync still overwrites the field on every run).
     * Only top-level mappings are reported; nested Matrix-block / relation
     * sub-mappings are not walked.
     *
     * @return list<string> field/attribute handles
     */
    public function mappedHandlesForElement(ElementInterface $element): array
    {
        $handles = [];

        foreach ($this->findLinksForElement($element) as $link) {
            foreach ($link->getMappingCollection() as $handle => $mapping) {
                if ($mapping->isActive()) {
                    $handles[$handle] = true;
                }
            }
        }

        return array_keys($handles);
    }

    /**
     * Persist a link.
     *
     * Writes to Project Config — the PC change handler {@see handleChangedLink}
     * then upserts the DB row. Mirrors craft-remote-entries' SourcesService::save.
     *
     * Unmappable mapping handles are pruned and the missing-element policies
     * healed to the endpoint shape before validation — hygiene rather than
     * validation, so both run on forced saves too and stored config never keeps
     * a handle the target can't write; both steps are idempotent. The link's
     * `id` is back-filled only after the PC write, since the row doesn't exist
     * until the change handler has run.
     */
    public function saveLink(Link $link, bool $runValidation = true): bool
    {
        $isNew = ! $link->id;

        $this->pruneUnknownMappings($link);

        $link->migrateProcessingForEndpointShape();

        if ($runValidation && ! $link->validate()) {
            Craft::info(
                'Link not saved due to validation errors: ' . json_encode($link->getErrors()),
                __METHOD__,
            );

            return false;
        }

        foreach ($this->getAllLinks() as $other) {
            if ($other->handle === $link->handle && $other->id !== $link->id) {
                $link->addError('handle', "A link with handle '{$link->handle}' already exists.");

                return false;
            }
        }

        if ($link->sortOrder === null) {
            $link->sortOrder = $this->nextSortOrder();
        }

        $link->ensureUid();

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_LINK)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_LINK, new LinkEvent([
                'link'  => $link,
                'isNew' => $isNew,
            ]));
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_LINKS_KEY . '.' . $link->uid,
            $link->getConfig(),
            "Save influx link “{$link->handle}”",
        );

        if (! $link->id) {
            $link->id = Db::idByUid(Table::LINKS, $link->uid) ?: null;
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_LINK)) {
            $this->trigger(self::EVENT_AFTER_SAVE_LINK, new LinkEvent([
                'link'  => $link,
                'isNew' => $isNew,
            ]));
        }

        return true;
    }

    /**
     * Drop mapping entries whose handle the target doesn't report as
     * mappable — stale natives after a rename, custom fields removed from the
     * entry type's layout, or natives the entry type now hides
     * ({@see \GlueAgency\Influx\targets\EntryTarget::nativeFieldDefinitions()}).
     * Pruning at save time keeps the stored config (Project Config YAML + DB row)
     * in lockstep with the target's field surface; re-adding a field simply makes
     * its handle mappable again.
     *
     * WHERE THIS RUNS: only from {@see saveLink()} — i.e. a builder save
     * ({@see LinkBuilderService::save()}) or a Feed Me import
     * ({@see \GlueAgency\Influx\integrations\craftcms\feedme\services\FeedMeService}).
     * NOT on `project-config/apply`: {@see handleChangedLink()} writes the row
     * straight from the PC payload. So a mapping that became unmappable survives
     * — and keeps syncing — until the link is next saved, by design: hygiene
     * belongs to the edit, not to the deploy. Log rows written for a since-pruned
     * handle label by raw handle, since {@see \GlueAgency\Influx\web\ItemRowPresenter::fieldLabels()}
     * reads the same reported surface.
     *
     * Only applied when the target's field surface includes custom fields:
     * a natives-only list means the link's criteria didn't resolve (no
     * section/type yet), and pruning then would throw away every
     * custom-field mapping on a half-configured link.
     */
    protected function pruneUnknownMappings(Link $link): void
    {
        $target = $this->targetForLink($link);

        if (! $target) {
            return;
        }

        $mappable = $target->getMappableFields($link);

        if (empty($mappable)) {
            return;
        }

        $known = [];
        $hasCustomFields = false;

        foreach ($mappable as $field) {
            $known[$field->handle] = true;

            if (! $field->native) {
                $hasCustomFields = true;
            }
        }

        if (! $hasCustomFields) {
            return;
        }

        $unknown = array_diff_key($link->mappings, $known);

        if (empty($unknown)) {
            return;
        }

        $link->mappings = array_intersect_key($link->mappings, $known);
        Craft::info(
            "Dropped unmappable mapping handle(s) on link '{$link->handle}': " . implode(', ', array_keys($unknown)),
            __METHOD__,
        );
    }

    /**
     * Delete a link by UID. Removing from PC triggers {@see handleDeletedLink}
     * which deletes the DB row.
     */
    public function deleteLinkByUid(string $uid): bool
    {
        $link = $this->getLinkByUid($uid);

        if (! $link) {
            return false;
        }

        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_LINK)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_LINK, new LinkEvent(['link' => $link]));
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_LINKS_KEY . '.' . $uid,
            "Delete influx link “{$link->handle}”",
        );

        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_LINK)) {
            $this->trigger(self::EVENT_AFTER_DELETE_LINK, new LinkEvent(['link' => $link]));
        }

        return true;
    }

    /**
     * An unsaved copy of a link, ready to prefill the builder as a NEW link:
     * the source's config with a fresh identity (no id/uid/sortOrder, and no
     * runtime last-run state), a unique suffixed handle, and a "(copy)" name.
     * Nothing is persisted — the builder saves it through the normal path
     * (which validates the handle), so the user can rename / adjust first.
     */
    public function buildDuplicate(Link $source): Link
    {
        $copy = clone $source;
        $copy->id = null;
        $copy->uid = null;
        $copy->sortOrder = null;
        $copy->lastRunAt = null;
        $copy->lastLogId = null;
        $copy->handle = $this->uniqueHandle($source->handle . 'Copy');
        $copy->name = $source->name . ' (copy)';

        return $copy;
    }

    /**
     * `$base`, or `$base` + an incrementing suffix, whichever is the first
     * handle no existing link already uses — so a prefilled duplicate handle
     * doesn't collide out of the gate (the user can still change it).
     */
    protected function uniqueHandle(string $base): string
    {
        $handle = $base;
        $n = 1;

        while ($this->getLinkByHandle($handle)) {
            $n++;
            $handle = $base . $n;
        }

        return $handle;
    }

    /**
     * Persist a new overview order from a list of link UIDs. Each moved link's
     * full config node is written back to Project Config with its 1-based
     * position; the PC change handler ({@see handleChangedLink}) then re-syncs
     * the DB row. Mirrors the PC-is-the-deployment-channel path {@see saveLink}
     * uses, so the order round-trips to YAML and deploys like any other link
     * change. Unknown UIDs are ignored; links whose position is unchanged are
     * left untouched to keep the YAML diff minimal.
     *
     * @param string[] $uids link UIDs in the desired order
     */
    public function saveOrder(array $uids): void
    {
        $byUid = [];

        foreach ($this->getAllLinks() as $link) {
            $byUid[$link->uid] = $link;
        }

        $pc = Craft::$app->getProjectConfig();
        $order = 1;

        foreach ($uids as $uid) {
            $link = $byUid[$uid] ?? null;

            if (! $link) {
                continue;
            }

            if ($link->sortOrder !== $order) {
                $link->sortOrder = $order;
                $pc->set(
                    self::CONFIG_LINKS_KEY . '.' . $uid,
                    $link->getConfig(),
                    "Reorder influx link “{$link->handle}”",
                );
            }

            $order++;
        }

        $this->links = null;
    }

    /**
     * Project Config add/update handler — fires when a link is saved through
     * the service or applied from YAML. Upserts the matching row in
     * `influx_links` so runtime reads stay in sync.
     */
    public function handleChangedLink(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = is_array($event->newValue) ? $event->newValue : [];
        $id = Db::idByUid(Table::LINKS, $uid);

        $columns = self::columnValuesFromConfig($data);
        $columns['uid'] = $uid;
        $columns['dateUpdated'] = Db::prepareDateForDb(new DateTime());

        if (! $id) {
            $columns['dateCreated'] = $columns['dateUpdated'];
            Craft::$app->getDb()->createCommand()
                ->insert(Table::LINKS, $columns)
                ->execute();
        } else {
            Craft::$app->getDb()->createCommand()
                ->update(Table::LINKS, $columns, ['id' => $id])
                ->execute();
        }

        $this->links = null;
    }

    /**
     * Project Config remove handler — deletes the matching row.
     */
    public function handleDeletedLink(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        Craft::$app->getDb()->createCommand()
            ->delete(Table::LINKS, ['uid' => $uid])
            ->execute();

        $this->links = null;
    }

    /**
     * Stamp a link's last run onto its own row — runtime state that outlives
     * the run's log. A direct column write (NOT Project Config, so it never
     * touches YAML or `dateUpdated`); `lastLogId` is null when the run wasn't
     * logged (logging disabled). Called by {@see LogsService::start()}. The
     * passed model is updated and the in-memory cache dropped as well, so an
     * already-loaded link stays coherent for the rest of the request.
     */
    public function recordRun(Link $link, ?int $logId, DateTime $when): void
    {
        if (! $link->id) {
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::LINKS, [
                'lastRunAt' => Db::prepareDateForDb($when),
                'lastLogId' => $logId,
            ], ['id' => $link->id])
            ->execute();

        $link->lastRunAt = $when;
        $link->lastLogId = $logId;
        $this->links = null;
    }

    /**
     * Null the `lastLogId` soft pointer on any link whose last-run log no
     * longer exists, leaving `lastRunAt` intact. One reconcile query serves a
     * single delete, a full clear, and retention GC alike — so a set
     * `lastLogId` always still resolves to a real log. Called by
     * {@see LogsService} after any log deletion.
     */
    public function forgetDeletedLogs(): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(
                Table::LINKS,
                ['lastLogId' => null],
                ['and',
                    ['not', ['lastLogId' => null]],
                    ['not in', 'lastLogId', (new Query())->select(['id'])->from(Table::LOGS)],
                ],
            )
            ->execute();

        $this->links = null;
    }

    /**
     * Map a Project Config link payload to DB column values — one column per
     * {@see Link::CONFIG_FIELDS} entry, nothing more: array-shaped fields
     * ({@see Link::JSON_FIELDS}) are JSON-encoded, the rest coerced per
     * {@see Link::COLUMN_CASTS}. Driving both off those declarations is what
     * keeps a new config field from needing an edit here too.
     *
     * A field the payload omits writes NULL for a JSON column (the empty-shape
     * contract on {@see Link::CONFIG_FIELDS}) and its cast's zero value
     * otherwise.
     */
    public static function columnValuesFromConfig(array $config): array
    {
        $columns = [];

        foreach (Link::CONFIG_FIELDS as $field) {
            $columns[$field] = in_array($field, Link::JSON_FIELDS, true)
                ? (isset($config[$field]) ? json_encode($config[$field]) : null)
                : Link::castColumnValue($field, $config[$field] ?? null);
        }

        return $columns;
    }

    /**
     * The position to give a link that doesn't have one yet — one past the
     * highest existing order, so new links land at the end of the overview.
     */
    protected function nextSortOrder(): int
    {
        $max = 0;

        foreach ($this->getAllLinks() as $existing) {
            $max = max($max, (int) $existing->sortOrder);
        }

        return $max + 1;
    }

    protected function createQuery(): Query
    {
        return (new Query())
            ->select('*')
            ->from(Table::LINKS)
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC]);
    }

    /**
     * The inverse of {@see columnValuesFromConfig()}: JSON columns decode (a
     * missing or unreadable one reads back as `[]`, per the empty-shape
     * contract), every other column coerces per its {@see Link::COLUMN_CASTS}
     * declaration — the same map the write side uses, so the two can't drift.
     * Every column the query selects is declared on `Link`; one that isn't would
     * have to be dropped here, since the Model base warns on unknown attributes.
     */
    protected function linkFromRow(array $row): Link
    {
        foreach (Link::JSON_FIELDS as $key) {
            $raw = $row[$key] ?? null;

            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $row[$key] = is_array($decoded) ? $decoded : [];
            } else {
                $row[$key] = [];
            }
        }

        foreach (array_keys(Link::COLUMN_CASTS) as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = Link::castColumnValue($column, $row[$column]);
            }
        }

        return new Link($row);
    }
}
