<?php

namespace GlueAgency\Influx\events;

use craft\events\ModelEvent;
use GlueAgency\Influx\models\Link;

/**
 * Fired around create/save/delete of a Link configuration.
 *
 *   Event::on(
 *       LinksService::class,
 *       LinksService::EVENT_AFTER_SAVE_LINK,
 *       function (LinkEvent $event) {
 *           if ($event->isNew) {}
 *       }
 *   );
 */
class LinkEvent extends ModelEvent
{
    public Link $link;
    public bool $isNew = false;
}
