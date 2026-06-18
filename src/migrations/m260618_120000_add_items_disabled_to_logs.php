<?php

namespace GlueAgency\Influx\migrations;

use Craft;
use craft\db\Migration;
use GlueAgency\Influx\db\Table;

/**
 * Adds the `itemsDisabled` counter to the logs table. Disabled items used to
 * fold into `itemsDeleted`; they now count on their own so a run can report
 * deletes and disables separately. Idempotent — fresh installs get the column
 * straight from {@see Install}.
 */
class m260618_120000_add_items_disabled_to_logs extends Migration
{
    public function safeUp(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LOGS, true);

        if ($schema !== null && ! isset($schema->columns['itemsDisabled'])) {
            $this->addColumn(
                Table::LOGS,
                'itemsDisabled',
                $this->integer()->defaultValue(0)->after('itemsDeleted'),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LOGS, true);

        if ($schema !== null && isset($schema->columns['itemsDisabled'])) {
            $this->dropColumn(Table::LOGS, 'itemsDisabled');
        }

        return true;
    }
}
