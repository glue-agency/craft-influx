<?php

namespace TDM\Influx\events;

use craft\base\ElementInterface;
use yii\base\Event;
use TDM\Influx\models\Feed;

/**
 * Fired around the processing of a single remote item.
 *
 *   beforeProcess  — $item is the raw remote payload, $element may be null
 *                    (will become a new element) or a matched existing one.
 *                    Set $skip = true to bypass this item.
 *   afterMapping   — mappings have been applied to $element but it hasn't
 *                    been saved yet. Mutate $element freely.
 *   afterSave      — element has been persisted. $action describes what
 *                    happened: 'created' | 'updated' | 'unchanged' | 'skipped'
 *                    | 'disabled' | 'deleted' | 'deleted-for-site'.
 */
class SyncItemEvent extends Event
{
    public Feed $feed;
    public array $item = [];
    public ?ElementInterface $element = null;
    public ?string $siteHandle = null;
    public bool $skip = false;
    public ?string $action = null;
}
