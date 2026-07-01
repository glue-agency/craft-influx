<?php

namespace GlueAgency\Influx\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use GlueAgency\Influx\db\Table;
use GlueAgency\Influx\services\LinksService;

/**
 * Adds `sortOrder` to the links table — a 1-based manual position driving the
 * drag-to-sort order on the Links overview. Round-trips through Project Config
 * like every other link property, so the order deploys across environments.
 *
 * Back-fills existing links by their current handle order (what the overview
 * showed before this bump, so nothing visibly moves) into both the DB column
 * and Project Config. Idempotent; fresh installs get the column from Install.
 */
class m260701_120000_add_sort_order_to_links extends Migration
{
    public function safeUp(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LINKS, true);

        if ($schema === null) {
            return true;
        }

        if (! isset($schema->columns['sortOrder'])) {
            $this->addColumn(Table::LINKS, 'sortOrder', $this->integer()->after('backup'));
        }

        // Seed a deterministic starting order from the existing (alphabetical)
        // display order, so the overview looks unchanged after upgrading.
        $pc = Craft::$app->getProjectConfig();

        $rows = (new Query())
            ->select(['id', 'uid', 'handle'])
            ->from(Table::LINKS)
            ->orderBy(['handle' => SORT_ASC])
            ->all();

        $order = 1;

        foreach ($rows as $row) {
            // DB is the runtime source of truth — set it directly so the order
            // is correct even if PC writes are muted in this migration context.
            $this->update(Table::LINKS, ['sortOrder' => $order], ['id' => $row['id']]);

            // PC is the deployment channel — write the full node back so the
            // sortOrder lands in YAML and the change handler stays in lockstep.
            $node = $pc->get(LinksService::CONFIG_LINKS_KEY . '.' . $row['uid']);

            if (is_array($node)) {
                $node['sortOrder'] = $order;
                $pc->set(
                    LinksService::CONFIG_LINKS_KEY . '.' . $row['uid'],
                    $node,
                    "Influx: seed sortOrder for link {$row['handle']}",
                );
            }

            $order++;
        }

        return true;
    }

    public function safeDown(): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::LINKS, true);

        if ($schema !== null && isset($schema->columns['sortOrder'])) {
            $this->dropColumn(Table::LINKS, 'sortOrder');
        }

        return true;
    }
}
