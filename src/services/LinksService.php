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
use yii\base\InvalidConfigException;

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

        ksort($this->links);

        return $this->links;
    }

    public function getLinkByHandle(string $handle): ?Link
    {
        $row = $this->createQuery()->where(['handle' => $handle])->one();

        return $row ? $this->linkFromRow($row) : null;
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
     * Find the first link whose target element type and criteria claim this
     * element. Used by the per-entry "Sync from remote" button and the
     * per-element sync endpoint.
     */
    public function findLinkForElement(ElementInterface $element): ?Link
    {
        $targets = Influx::getInstance()->targets;

        foreach ($this->getAllLinks() as $link) {
            $target = $targets->forLink($link);

            if ($target && $target->claimsElement($link, $element)) {
                return $link;
            }
        }

        return null;
    }

    /**
     * Persist a link.
     *
     * Writes to Project Config — the PC change handler {@see handleChangedLink}
     * then upserts the DB row. Mirrors craft-remote-entries' SourcesService::save.
     */
    public function saveLink(Link $link, bool $runValidation = true): bool
    {
        $isNew = ! $link->id;

        // Hygiene, not validation — runs on forced saves too, so the stored
        // config never carries handles the target can't map.
        $this->pruneUnknownMappings($link);

        if ($runValidation && ! $link->validate()) {
            Craft::info(
                'Link not saved due to validation errors: ' . json_encode($link->getErrors()),
                __METHOD__,
            );

            return false;
        }

        // Reject handle collisions when creating or renaming.
        foreach ($this->getAllLinks() as $other) {
            if ($other->handle === $link->handle && $other->id !== $link->id) {
                $link->addError('handle', "A link with handle '{$link->handle}' already exists.");

                return false;
            }
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

        // PC handler has now upserted the DB row; back-fill the id.
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
     * mappable — stale natives after a rename, or custom fields removed from
     * the entry type's layout. Pruning at save time keeps the stored config
     * (Project Config YAML + DB row) in lockstep with the target's field
     * surface; re-adding a field simply makes its handle mappable again.
     *
     * Only applied when the target's field surface includes custom fields:
     * a natives-only list means the link's criteria didn't resolve (no
     * section/type yet), and pruning then would throw away every
     * custom-field mapping on a half-configured link.
     */
    protected function pruneUnknownMappings(Link $link): void
    {
        $target = Influx::getInstance()->targets->forLink($link);

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
            $known[$field['handle']] = true;

            if (empty($field['native'])) {
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
     * Duplicate a link under a new handle.
     */
    public function duplicateLink(string $sourceHandle, string $newHandle, ?string $newName = null): Link
    {
        if (! ($source = $this->getLinkByHandle($sourceHandle))) {
            throw new InvalidConfigException("Link '{$sourceHandle}' not found.");
        }

        if ($this->getLinkByHandle($newHandle)) {
            throw new InvalidConfigException("A link with handle '{$newHandle}' already exists.");
        }

        $copy = clone $source;
        $copy->id = null;
        $copy->uid = null;
        $copy->handle = $newHandle;
        $copy->name = $newName ?? ($source->name . ' (copy)');

        if (! $this->saveLink($copy)) {
            throw new InvalidConfigException("Failed to duplicate '{$sourceHandle}': "
                . json_encode($copy->getErrors()));
        }

        return $copy;
    }

    // -- Project Config listeners --------------------------------------------

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

    // -- helpers -------------------------------------------------------------

    /**
     * Map a Project Config link payload to DB column values. Used by both the
     * PC change handler and the install/upgrade migrations (for seeding the
     * table from PC entries that pre-date the schema bump).
     *
     * Array-shaped fields are JSON-encoded; scalars and nullables pass through
     * with explicit type coercion so callers don't have to think about it.
     */
    public static function columnValuesFromConfig(array $config): array
    {
        $columns = [
            'name'           => (string) ($config['name'] ?? ''),
            'handle'         => (string) ($config['handle'] ?? ''),
            'elementType'    => (string) ($config['elementType'] ?? ''),
            'endpoint'       => $config['endpoint'] ?? null,
            'itemEndpoint'   => $config['itemEndpoint'] ?? null,
            'rootNode'       => $config['rootNode'] ?? null,
            'paginatorNode'  => $config['paginatorNode'] ?? null,
            'totalCountNode' => $config['totalCountNode'] ?? null,
            'pageCountNode'  => $config['pageCountNode'] ?? null,
            'backup'         => ! empty($config['backup']),
        ];

        foreach (Link::JSON_FIELDS as $key) {
            $columns[$key] = isset($config[$key]) ? json_encode($config[$key]) : null;
        }

        return $columns;
    }

    protected function createQuery(): Query
    {
        return (new Query())
            ->select('*')
            ->from(Table::LINKS);
    }

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

        // Boolean / int columns come back as strings on some drivers.
        $row['backup'] = ! empty($row['backup']);
        $row['id'] = (int) $row['id'];

        // Drop columns Link doesn't know about; the Model base would warn.
        unset($row['dateCreated'], $row['dateUpdated']);

        return new Link($row);
    }
}
