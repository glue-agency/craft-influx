<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use TDM\Influx\events\LinkEvent;
use TDM\Influx\Influx;
use TDM\Influx\models\Link;
use yii\base\InvalidConfigException;

/**
 * Reads and writes Influx links to Craft's Project Config.
 *
 * Links live under `influx.links.{uid}` and round-trip to YAML the same way
 * Craft's own sections, entry types, volumes, etc. do. Writes are gated by
 * `allowAdminChanges` automatically because Project Config itself enforces
 * that constraint.
 */
class LinksService extends Component
{
    public const CONFIG_LINKS_KEY = 'influx.links';

    public const EVENT_BEFORE_SAVE_LINK = 'beforeSaveLink';
    public const EVENT_AFTER_SAVE_LINK = 'afterSaveLink';
    public const EVENT_BEFORE_DELETE_LINK = 'beforeDeleteLink';
    public const EVENT_AFTER_DELETE_LINK = 'afterDeleteLink';

    /** @var Link[]|null in-memory cache keyed by handle */
    private ?array $links = null;

    /**
     * @return Link[] indexed by handle
     */
    public function getAllLinks(): array
    {
        if ($this->links !== null) {
            return $this->links;
        }

        $this->links = [];

        $configs = Craft::$app->getProjectConfig()->get(self::CONFIG_LINKS_KEY) ?? [];

        foreach ($configs as $uid => $config) {
            if (!is_array($config)) {
                continue;
            }
            $this->links[$config['handle'] ?? $uid] = $this->createLinkFromConfig($uid, $config);
        }

        ksort($this->links);

        return $this->links;
    }

    public function getLinkByHandle(string $handle): ?Link
    {
        return $this->getAllLinks()[$handle] ?? null;
    }

    public function getLinkByUid(string $uid): ?Link
    {
        $config = Craft::$app->getProjectConfig()->get(self::CONFIG_LINKS_KEY . '.' . $uid);
        if (!is_array($config)) {
            return null;
        }
        return $this->createLinkFromConfig($uid, $config);
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
     * Persist a link to Project Config.
     *
     * @param bool $runValidation
     * @return bool true on success
     */
    public function saveLink(Link $link, bool $runValidation = true): bool
    {
        $isNew = !$link->uid;

        if ($runValidation && !$link->validate()) {
            Craft::info('Link not saved due to validation errors.', __METHOD__);
            return false;
        }

        // Reject handle collisions when creating or renaming.
        foreach ($this->getAllLinks() as $other) {
            if ($other->handle === $link->handle && $other->uid !== $link->uid) {
                $link->addError('handle', "A link with handle '{$link->handle}' already exists.");
                return false;
            }
        }

        $link->ensureUid();

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_LINK)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_LINK, new LinkEvent([
                'link' => $link,
                'isNew' => $isNew,
            ]));
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_LINKS_KEY . '.' . $link->uid,
            $link->getConfig(),
            "Save influx link “{$link->handle}”",
        );

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_LINK)) {
            $this->trigger(self::EVENT_AFTER_SAVE_LINK, new LinkEvent([
                'link' => $link,
                'isNew' => $isNew,
            ]));
        }

        return true;
    }

    /**
     * Delete a link by UID.
     */
    public function deleteLinkByUid(string $uid): bool
    {
        $link = $this->getLinkByUid($uid);
        if (!$link) {
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
        $source = $this->getLinkByHandle($sourceHandle)
            ?? throw new InvalidConfigException("Link '{$sourceHandle}' not found.");

        if ($this->getLinkByHandle($newHandle)) {
            throw new InvalidConfigException("A link with handle '{$newHandle}' already exists.");
        }

        $copy = clone $source;
        $copy->uid = null;
        $copy->handle = $newHandle;
        $copy->name = $newName ?? ($source->name . ' (copy)');

        if (!$this->saveLink($copy)) {
            throw new InvalidConfigException("Failed to duplicate '{$sourceHandle}': "
                . json_encode($copy->getErrors()));
        }

        return $copy;
    }

    // -- Project Config listeners --------------------------------------------

    /**
     * Project Config add/update handler — invoked when a link is added or
     * changed (including remotely, e.g. via `project-config/apply`).
     */
    public function handleChangedLink(ConfigEvent $event): void
    {
        // The plugin doesn't keep a DB index of links — the in-memory cache
        // is the only thing that can go stale.
        $this->links = null;
    }

    public function handleDeletedLink(ConfigEvent $event): void
    {
        $this->links = null;
    }

    // -- helpers -------------------------------------------------------------

    private function createLinkFromConfig(string $uid, array $config): Link
    {
        $config['uid'] = $uid;
        return new Link($config);
    }
}
