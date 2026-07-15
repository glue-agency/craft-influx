<?php

namespace GlueAgency\Influx\migrations;

use craft\db\Migration;
use GlueAgency\Influx\db\Table;

/**
 * Two log-table tweaks:
 *
 *   - Widen `influx_log_items.matchValue` from VARCHAR(255) to TEXT. Remote
 *     match values (compound external IDs, long slugs) can exceed 255, and an
 *     over-length value threw from the buffered batch insert, aborting a live
 *     sync run and losing the rest of that page's buffered rows.
 *   - Index `influx_logs.status`. `LogsService::errorLogCount()` — the CP nav
 *     badge — filters on it on every control-panel page load.
 */
class m260714_120000_widen_log_match_value_and_index_status extends Migration
{
    public function safeUp(): bool
    {
        $this->alterColumn(Table::LOG_ITEMS, 'matchValue', $this->text()->null());
        $this->createIndex(null, Table::LOGS, ['status']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->alterColumn(Table::LOG_ITEMS, 'matchValue', $this->string(255)->null());
        // Leave the status index on down — its name isn't reliably reconstructable across Craft 4/5

        return true;
    }
}
