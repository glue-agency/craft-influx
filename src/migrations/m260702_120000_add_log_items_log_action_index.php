<?php

namespace GlueAgency\Influx\migrations;

use Craft;
use craft\db\Migration;
use GlueAgency\Influx\db\Table;

/**
 * Replaces the single-column `logId` index on the log-items table with a
 * composite `(logId, action)` one. The composite's leftmost prefix still
 * serves every logId-only lookup (including the FK to the logs table), and
 * additionally covers the per-action counts and status filters on the log
 * detail view — so the old single index would be pure write amplification
 * on the hottest write path.
 *
 * Idempotent; fresh installs get the composite from Install.
 */
class m260702_120000_add_log_items_log_action_index extends Migration
{
    public function safeUp(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LOG_ITEMS, true);

        if ($schema === null) {
            return true;
        }

        // Create the composite before dropping the single index, so the FK on
        // logId always has a usable (prefix) index while we shuffle.
        if ($this->findIndexName(['logId', 'action']) === null) {
            $this->createIndex(null, Table::LOG_ITEMS, ['logId', 'action']);
        }

        $single = $this->findIndexName(['logId']);

        if ($single !== null) {
            $this->dropIndex($single, Table::LOG_ITEMS);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LOG_ITEMS, true);

        if ($schema === null) {
            return true;
        }

        if ($this->findIndexName(['logId']) === null) {
            $this->createIndex(null, Table::LOG_ITEMS, ['logId']);
        }

        $composite = $this->findIndexName(['logId', 'action']);

        if ($composite !== null) {
            $this->dropIndex($composite, Table::LOG_ITEMS);
        }

        return true;
    }

    /**
     * Find the name of a non-unique index on the log-items table matching
     * exactly the given column list (order-sensitive), or null.
     *
     * @param string[] $columns
     */
    protected function findIndexName(array $columns): ?string
    {
        $indexes = Craft::$app->getDb()->getSchema()->getTableIndexes(Table::LOG_ITEMS, true);

        foreach ($indexes as $index) {
            if (! $index->isUnique && $index->columnNames === $columns) {
                return $index->name;
            }
        }

        return null;
    }
}
