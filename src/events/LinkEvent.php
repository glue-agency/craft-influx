<?php

namespace GlueAgency\Influx\events;

use craft\events\ModelEvent;
use GlueAgency\Influx\models\Link;

/**
 * Fired around create/save/delete of a Link configuration.
 */
class LinkEvent extends ModelEvent
{
    public Link $link;
    public bool $isNew = false;
}
