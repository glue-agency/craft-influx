<?php

namespace GlueAgency\Influx\records;

use craft\db\ActiveRecord;
use GlueAgency\Influx\db\Table;

/**
 * @property int $id
 * @property string $linkHandle
 * @property string $trigger
 * @property ?string $siteHandle
 * @property ?string $offsetHandle
 * @property ?int $elementId
 * @property string $status
 * @property int $itemsSeen
 * @property int $itemsCreated
 * @property int $itemsUpdated
 * @property int $itemsUnchanged
 * @property int $itemsSkipped
 * @property int $itemsDeleted
 * @property int $itemsDisabled
 * @property string $startedAt
 * @property ?string $finishedAt
 * @property ?string $error
 */
class Log extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOGS;
    }
}
