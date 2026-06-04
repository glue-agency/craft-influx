<?php

namespace TDM\Influx\events;

use craft\events\ModelEvent;
use TDM\Influx\models\Link;

/**
 * Fired around create/save/delete of a Link configuration.
 */
class LinkEvent extends ModelEvent
{
    public Link $link;
    public bool $isNew = false;
}
