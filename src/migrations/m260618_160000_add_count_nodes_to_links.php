<?php

namespace GlueAgency\Influx\migrations;

use Craft;
use craft\db\Migration;
use GlueAgency\Influx\db\Table;

/**
 * Adds `totalCountNode` + `pageCountNode` to the links table — optional Hash
 * paths to the feed's total-item / total-page count, so the sync can report a
 * real progress % (and a future batched job can page per step). Idempotent;
 * fresh installs get them from Install.
 */
class m260618_160000_add_count_nodes_to_links extends Migration
{
    public function safeUp(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LINKS, true);

        if ($schema === null) {
            return true;
        }

        if (! isset($schema->columns['totalCountNode'])) {
            $this->addColumn(Table::LINKS, 'totalCountNode', $this->string()->null()->after('paginatorNode'));
        }

        if (! isset($schema->columns['pageCountNode'])) {
            $this->addColumn(Table::LINKS, 'pageCountNode', $this->string()->null()->after('totalCountNode'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LINKS, true);

        if ($schema === null) {
            return true;
        }

        foreach (['pageCountNode', 'totalCountNode'] as $column) {
            if (isset($schema->columns[$column])) {
                $this->dropColumn(Table::LINKS, $column);
            }
        }

        return true;
    }
}
