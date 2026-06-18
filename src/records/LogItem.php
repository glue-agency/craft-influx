<?php

namespace GlueAgency\Influx\records;

use craft\db\ActiveRecord;
use GlueAgency\Influx\db\Table;

/**
 * @property int $id
 * @property int $logId
 * @property ?int $elementId
 * @property ?string $matchValue
 * @property string $action
 * @property ?string $message
 * @property ?string $fieldErrors
 * @property ?string $payload
 */
class LogItem extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG_ITEMS;
    }
}
