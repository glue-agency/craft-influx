<?php

namespace TDM\Influx\migrations;

use craft\db\Migration;
use TDM\Influx\db\Table;

/**
 * Rename influx_logs.feedHandle to linkHandle (Feed → Link rename).
 */
class m250603_120000_rename_feed_handle_to_link_handle extends Migration
{
    public function safeUp(): bool
    {
        $columns = $this->db->getTableSchema(Table::LOGS)?->columns ?? [];

        if (isset($columns['feedHandle']) && !isset($columns['linkHandle'])) {
            $this->renameColumn(Table::LOGS, 'feedHandle', 'linkHandle');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $columns = $this->db->getTableSchema(Table::LOGS)?->columns ?? [];

        if (isset($columns['linkHandle']) && !isset($columns['feedHandle'])) {
            $this->renameColumn(Table::LOGS, 'linkHandle', 'feedHandle');
        }

        return true;
    }
}
