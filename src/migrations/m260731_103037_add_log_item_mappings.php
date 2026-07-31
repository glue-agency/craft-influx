<?php

namespace GlueAgency\Influx\migrations;

use craft\db\Migration;
use GlueAgency\Influx\db\Table;

/**
 * Add `influx_log_items.mappings` — the presented per-field mapping rows a run
 * produced for one item, stored as JSON.
 *
 * The log drill-down used to re-inspect the stored payload with a live dry run
 * and then overlay the stored field errors and changed flags on top. That reads
 * the element's PRESENT state, so a successfully-updated item showed "no change"
 * on every row and a run-time failure couldn't be reproduced at all. Persisting
 * the run's own presented rows makes the drill-down a record of what happened
 * instead of a re-enactment.
 *
 * Existing rows keep a null column and render flat with a notice — no
 * back-fill is possible, the data only exists at run time.
 */
class m260731_103037_add_log_item_mappings extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn(Table::LOG_ITEMS, 'mappings', $this->longText()->null()->after('payload'));

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(Table::LOG_ITEMS, 'mappings');

        return true;
    }
}
