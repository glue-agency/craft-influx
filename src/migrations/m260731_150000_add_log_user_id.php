<?php

namespace GlueAgency\Influx\migrations;

use craft\db\Migration;
use GlueAgency\Influx\db\Table;

/**
 * Add `influx_logs.userId` — the Craft user who triggered the run.
 *
 * A run's `trigger` says by which mechanism it started, never who asked for it,
 * so a CP-triggered import was indistinguishable from anyone else's. The user is
 * captured at trigger time and carried to the log, which makes the overview
 * answer "who ran this" alongside "how".
 *
 * No foreign key and no index, both on purpose: a cascade would erase the
 * attribution the column exists to keep (and a restrict would block deleting the
 * user), and nothing filters on it — it's read back per row for display only.
 *
 * Existing rows keep a null column and read as "no user": the identity only
 * exists at trigger time, so no back-fill is possible.
 */
class m260731_150000_add_log_user_id extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn(Table::LOGS, 'userId', $this->integer()->null()->after('trigger'));

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(Table::LOGS, 'userId');

        return true;
    }
}
