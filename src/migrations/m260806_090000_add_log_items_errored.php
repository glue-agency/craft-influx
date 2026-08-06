<?php

namespace GlueAgency\Influx\migrations;

use craft\db\Migration;
use craft\db\Query;
use GlueAgency\Influx\db\Table;
use GlueAgency\Influx\enums\ItemAction;

/**
 * Add `influx_logs.itemsErrored` — the counter the `error` action never had.
 *
 * Every other action owned a counter column, and {@see ItemAction::countedCases()}
 * is what both the overviews' result pills and the log viewer's counter row are
 * built from — and that counter row IS the item filter. So an action with no
 * column was an action with no pill and no filter: a run that errored on a dozen
 * items finished `ok`, summarised as created/updated/unchanged, while the CP nav
 * badge counted the log as needing a look. The error rows were in the list the
 * whole time with no way to reach them but paging through every item.
 *
 * Back-filled from the rows themselves, so an existing log reports what it
 * already recorded rather than reading as clean.
 */
class m260806_090000_add_log_items_errored extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn(Table::LOGS, 'itemsErrored', $this->integer()->defaultValue(0)->after('itemsDisabled'));

        $counts = (new Query())
            ->select(['logId', 'total' => 'COUNT(*)'])
            ->from(Table::LOG_ITEMS)
            ->where(['action' => ItemAction::ERROR->value])
            ->groupBy(['logId'])
            ->all($this->db);

        foreach ($counts as $row) {
            $this->update(Table::LOGS, ['itemsErrored' => (int) $row['total']], ['id' => $row['logId']], [], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(Table::LOGS, 'itemsErrored');

        return true;
    }
}
