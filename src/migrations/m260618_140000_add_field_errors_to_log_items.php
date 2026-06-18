<?php

namespace GlueAgency\Influx\migrations;

use Craft;
use craft\db\Migration;
use GlueAgency\Influx\db\Table;

/**
 * Adds `fieldErrors` to the log-items table — a JSON map of {handle: message}
 * for the fields whose strategy threw during the run. The drill-down can't
 * reproduce non-deterministic failures (e.g. an asset upload) by re-inspecting
 * the payload, so the per-field errors are stored at run time and overlaid
 * onto the right field rows. Idempotent; fresh installs get it from Install.
 */
class m260618_140000_add_field_errors_to_log_items extends Migration
{
    public function safeUp(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LOG_ITEMS, true);

        if ($schema !== null && ! isset($schema->columns['fieldErrors'])) {
            $this->addColumn(Table::LOG_ITEMS, 'fieldErrors', $this->text()->null()->after('message'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LOG_ITEMS, true);

        if ($schema !== null && isset($schema->columns['fieldErrors'])) {
            $this->dropColumn(Table::LOG_ITEMS, 'fieldErrors');
        }

        return true;
    }
}
