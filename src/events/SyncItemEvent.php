<?php

namespace GlueAgency\Influx\events;

use craft\base\ElementInterface;
use craft\events\ModelEvent;
use GlueAgency\Influx\models\Link;

/**
 * Fired around the processing of a single remote item, at three points:
 * EVENT_BEFORE_ITEM (set `skip = true` to bypass it, or swap `element` to
 * retarget), EVENT_AFTER_ITEM_MAPPING (mappings applied, not yet saved — mutate
 * `element` freely), and EVENT_AFTER_ITEM (`element` saved; `action` is the
 * outcome: created/updated/unchanged/skipped/disabled/deleted/deleted-for-site).
 *
 *   Event::on(
 *       SynchronizationService::class,
 *       SynchronizationService::EVENT_BEFORE_ITEM,
 *       function (SyncItemEvent $event) {
 *           $event->skip = true;
 *       }
 *   );
 */
class SyncItemEvent extends ModelEvent
{
    public Link $link;
    public array $item = [];
    public ?ElementInterface $element = null;
    public ?string $siteHandle = null;
    public bool $skip = false;
    public ?string $action = null;
}
