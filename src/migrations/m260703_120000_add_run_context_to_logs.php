<?php

namespace GlueAgency\Influx\migrations;

use Craft;
use craft\db\Migration;
use GlueAgency\Influx\db\Table;

/**
 * Adds the run-context columns to the logs table: `offsetHandle` (the
 * sliding-window preset a run was triggered with) and `elementId` (the
 * resource a single-element run was triggered for), so the log viewer can
 * show what a run actually covered. Idempotent — fresh installs get the
 * columns straight from {@see Install}.
 */
class m260703_120000_add_run_context_to_logs extends Migration
{
    public function safeUp(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LOGS, true);

        if ($schema !== null && ! isset($schema->columns['offsetHandle'])) {
            $this->addColumn(
                Table::LOGS,
                'offsetHandle',
                $this->string(100)->null()->after('siteHandle'),
            );
        }

        if ($schema !== null && ! isset($schema->columns['elementId'])) {
            $this->addColumn(
                Table::LOGS,
                'elementId',
                $this->integer()->null()->after('offsetHandle'),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LOGS, true);

        if ($schema !== null && isset($schema->columns['offsetHandle'])) {
            $this->dropColumn(Table::LOGS, 'offsetHandle');
        }

        if ($schema !== null && isset($schema->columns['elementId'])) {
            $this->dropColumn(Table::LOGS, 'elementId');
        }

        return true;
    }
}
