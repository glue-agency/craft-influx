<?php

namespace TDM\Influx\records;

use craft\db\ActiveRecord;
use TDM\Influx\db\Table;

/**
 * @property int $id
 * @property int $logId
 * @property ?int $elementId
 * @property ?string $matchValue
 * @property string $action
 * @property ?string $message
 * @property ?string $payload
 */
class LogItem extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG_ITEMS;
    }
}
