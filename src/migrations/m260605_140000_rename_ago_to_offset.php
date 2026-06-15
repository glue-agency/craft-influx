<?php

namespace GlueAgency\Influx\migrations;

use Craft;
use craft\db\Migration;
use GlueAgency\Influx\db\Table;
use GlueAgency\Influx\services\LinksService;

/**
 * Catch-up migration for environments that ran `m260605_120000` before the
 * `ago` → `offset` rename was finalised in that file.
 *
 * Two independent renames, both idempotent:
 *
 *   1. DB column `influx_links.ago` → `influx_links.offset` (if the old name
 *      is still present).
 *   2. PC key `influx.links.{uid}.ago` → `…offset` for every link that still
 *      carries the legacy key, so YAML on disk lines up with the new schema
 *      and the PC change handler re-upserts the row with the renamed data.
 *
 * Fresh installs run `Install.php` straight to the new column name and PC
 * starts empty, so neither branch fires on them.
 */
class m260605_140000_rename_ago_to_offset extends Migration
{
    public function safeUp(): bool
    {
        $db = Craft::$app->getDb();
        $schema = $db->getTableSchema(Table::LINKS, true);

        if ($schema !== null && isset($schema->columns['ago']) && ! isset($schema->columns['offset'])) {
            $this->renameColumn(Table::LINKS, 'ago', 'offset');
        }

        $pc = Craft::$app->getProjectConfig();
        $links = $pc->get(LinksService::CONFIG_LINKS_KEY) ?? [];

        if (is_array($links)) {
            foreach ($links as $uid => $config) {
                if (! is_array($config) || ! array_key_exists('ago', $config)) {
                    continue;
                }

                if (! isset($config['offset'])) {
                    $config['offset'] = $config['ago'];
                }
                unset($config['ago']);

                $pc->set(
                    LinksService::CONFIG_LINKS_KEY . ".{$uid}",
                    $config,
                    "Influx: rename ago→offset for link {$uid}",
                );
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
