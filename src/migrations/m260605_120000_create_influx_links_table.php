<?php

namespace TDM\Influx\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\Db;
use TDM\Influx\db\Table;
use TDM\Influx\services\LinksService;

/**
 * Create the `influx_links` table and seed it from Project Config.
 *
 * Architecture: the DB is the runtime source of truth. Project Config exists
 * only as a deployment channel — `project-config/apply` triggers PC change
 * handlers that upsert DB rows, and `project-config/rebuild` reads the DB to
 * emit the YAML. Runtime reads never touch PC.
 *
 * This migration seeds the table from any links already present in PC so the
 * plugin keeps working after the schema bump (PC change events do not replay
 * for already-applied entries).
 */
class m260605_120000_create_influx_links_table extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Table::LINKS, [
            'id'              => $this->primaryKey(),
            'name'            => $this->string()->notNull(),
            'handle'          => $this->string(100)->notNull(),
            'elementType'     => $this->string()->notNull()->defaultValue(''),
            'elementCriteria' => $this->text()->null(),
            'endpoint'        => $this->text()->null(),
            'itemEndpoint'    => $this->text()->null(),
            'siteEndpoints'   => $this->text()->null(),
            'auth'            => $this->text()->null(),
            'rootNode'        => $this->string()->null(),
            'paginatorNode'   => $this->string()->null(),
            'match'           => $this->text()->null(),
            'mappings'        => $this->longText()->null(),
            'processing'      => $this->text()->null(),
            'offset'          => $this->text()->null(),
            'backup'          => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated'     => $this->dateTime()->notNull(),
            'dateUpdated'     => $this->dateTime()->notNull(),
            'uid'             => $this->uid(),
        ]);

        $this->createIndex(null, Table::LINKS, ['handle'], true);
        $this->createIndex(null, Table::LINKS, ['uid'], true);
        $this->createIndex(null, Table::LINKS, ['elementType']);

        // Seed from existing Project Config. PC change handlers won't re-fire
        // for already-applied entries, so the table starts empty without this.
        //
        // Also rewrites the legacy `ago` key (renamed in this version) to
        // `offset` in PC so YAML on disk lines up with the new schema.
        $pc = Craft::$app->getProjectConfig();
        $links = $pc->get(LinksService::CONFIG_LINKS_KEY) ?? [];

        if (is_array($links)) {
            $now = Db::prepareDateForDb(new \DateTime());
            foreach ($links as $uid => $config) {
                if (!is_array($config)) {
                    continue;
                }

                if (array_key_exists('ago', $config)) {
                    if (!isset($config['offset'])) {
                        $config['offset'] = $config['ago'];
                    }
                    unset($config['ago']);
                    $pc->set(
                        LinksService::CONFIG_LINKS_KEY . ".{$uid}",
                        $config,
                        "Influx: rename ago→offset for link {$uid}",
                    );
                }

                // The PC `set` above may already have triggered the change
                // handler, which inserts the row. Skip if so.
                if (Db::idByUid(Table::LINKS, $uid)) {
                    continue;
                }

                $this->insert(Table::LINKS, array_merge(
                    LinksService::columnValuesFromConfig($config),
                    [
                        'uid'         => $uid,
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                    ],
                ));
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LINKS);
        return true;
    }
}
