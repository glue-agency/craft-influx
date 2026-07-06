<?php

namespace GlueAgency\Influx\migrations;

use craft\db\Migration;
use GlueAgency\Influx\db\Table;

/**
 * Adds the runtime last-run tracking columns to `influx_links`: `lastRunAt`,
 * a timestamp that survives log deletion, and `lastLogId`, a soft pointer to
 * the run's log for quick access (nulled when that log is deleted). Neither is
 * a Project Config field — they're local runtime state, so the PC change
 * handler ({@see \GlueAgency\Influx\services\LinksService::handleChangedLink()})
 * leaves them untouched (its column set is config-only).
 */
class m260706_120000_add_link_last_run extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists(Table::LINKS, 'lastRunAt')) {
            $this->addColumn(Table::LINKS, 'lastRunAt', $this->dateTime()->null());
        }

        if (! $this->db->columnExists(Table::LINKS, 'lastLogId')) {
            $this->addColumn(Table::LINKS, 'lastLogId', $this->integer()->null());
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(Table::LINKS, 'lastLogId')) {
            $this->dropColumn(Table::LINKS, 'lastLogId');
        }

        if ($this->db->columnExists(Table::LINKS, 'lastRunAt')) {
            $this->dropColumn(Table::LINKS, 'lastRunAt');
        }

        return true;
    }
}
