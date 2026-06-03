<?php

namespace TDM\Influx\events;

use yii\base\Event;
use TDM\Influx\models\Link;

/**
 * Fired around create/save/delete of a Link configuration.
 */
class LinkEvent extends Event
{
    public Link $link;
    public bool $isNew = false;
}
