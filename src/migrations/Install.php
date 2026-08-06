<?php

namespace GlueAgency\Influx\migrations;

use craft\db\Migration;
use GlueAgency\Influx\db\Table;

class Install extends Migration
{
    /**
     * Column value domains that live nowhere else in schema form:
     *
     *   - links.lastRunAt / links.lastLogId — runtime last-run tracking, NOT
     *     Project Config; lastLogId is a soft pointer, nulled when its log is
     *     deleted.
     *   - logs.trigger — console | cp | element | queue.
     *   - logs.userId — the Craft user who triggered the run, captured at
     *     trigger time; null for console/cron runs, which nobody asked for.
     *     Deliberately NO foreign key: a cascade would erase the very
     *     attribution the column exists to keep, and a restrict would block
     *     deleting a user who ever pressed Sync. No index either — nothing
     *     filters on it, it's only read back per row for display.
     *   - logs.offsetHandle — the sliding-window preset the run used.
     *   - logs.elementId — the resource a single-element run was triggered for.
     *   - logs.status — running | ok | error.
     *   - logItems.action — created | updated | unchanged | skipped | disabled |
     *     deleted | deleted-for-site | error.
     *   - logItems.fieldErrors — {handle: message} for fields whose strategy threw.
     *   - logItems.changedFields — JSON list of mapping handles that changed in
     *     this run.
     *   - logItems.payload — raw remote item JSON (optional).
     *   - logItems.mappings — the presented per-field mapping rows the run
     *     produced, JSON, as {@see \GlueAgency\Influx\web\ItemRowPresenter::presentMappingResults()}
     *     emitted them; the log drill-down's display source. Null when the item
     *     produced none (sweep rows) or the snapshot was too big to store.
     *
     * Two of the indexes aren't obvious: logs.status is indexed because
     * {@see \GlueAgency\Influx\services\LogsService::errorLogCount()} — the CP
     * nav badge — filters on it on every page load, and the composite
     * [logId, action] on log items also serves logId-only lookups through its
     * leftmost prefix.
     */
    public function safeUp(): bool
    {
        $this->dropTableIfExists(Table::LOG_ITEMS);
        $this->dropTableIfExists(Table::LOGS);
        $this->dropTableIfExists(Table::LINKS);

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
            'totalCountNode'  => $this->string()->null(),
            'pageCountNode'   => $this->string()->null(),
            'match'           => $this->text()->null(),
            'mappings'        => $this->longText()->null(),
            'processing'      => $this->text()->null(),
            'offset'          => $this->text()->null(),
            'backup'          => $this->boolean()->notNull()->defaultValue(false),
            'sortOrder'       => $this->integer(),
            'lastRunAt'       => $this->dateTime()->null(),
            'lastLogId'       => $this->integer()->null(),
            'dateCreated'     => $this->dateTime()->notNull(),
            'dateUpdated'     => $this->dateTime()->notNull(),
            'uid'             => $this->uid(),
        ]);

        $this->createIndex(null, Table::LINKS, ['handle'], true);
        $this->createIndex(null, Table::LINKS, ['uid'], true);
        $this->createIndex(null, Table::LINKS, ['elementType']);

        $this->createTable(Table::LOGS, [
            'id'             => $this->primaryKey(),
            'linkHandle'     => $this->string(100)->notNull(),
            'trigger'        => $this->string(30)->notNull(),
            'userId'         => $this->integer()->null(),
            'siteHandle'     => $this->string(100)->null(),
            'offsetHandle'   => $this->string(100)->null(),
            'elementId'      => $this->integer()->null(),
            'status'         => $this->string(20)->notNull(),
            'itemsSeen'      => $this->integer()->defaultValue(0),
            'itemsCreated'   => $this->integer()->defaultValue(0),
            'itemsUpdated'   => $this->integer()->defaultValue(0),
            'itemsUnchanged' => $this->integer()->defaultValue(0),
            'itemsSkipped'   => $this->integer()->defaultValue(0),
            'itemsDeleted'   => $this->integer()->defaultValue(0),
            'itemsDisabled'  => $this->integer()->defaultValue(0),
            'itemsErrored'   => $this->integer()->defaultValue(0),
            'startedAt'      => $this->dateTime()->notNull(),
            'finishedAt'     => $this->dateTime()->null(),
            'error'          => $this->text()->null(),
            'dateCreated'    => $this->dateTime()->notNull(),
            'dateUpdated'    => $this->dateTime()->notNull(),
            'uid'            => $this->uid(),
        ]);

        $this->createIndex(null, Table::LOGS, ['linkHandle']);
        $this->createIndex(null, Table::LOGS, ['startedAt']);
        $this->createIndex(null, Table::LOGS, ['status']);

        $this->createTable(Table::LOG_ITEMS, [
            'id'            => $this->primaryKey(),
            'logId'         => $this->integer()->notNull(),
            'elementId'     => $this->integer()->null(),
            'matchValue'    => $this->text()->null(),
            'action'        => $this->string(30)->notNull(),
            'message'       => $this->text()->null(),
            'fieldErrors'   => $this->text()->null(),
            'changedFields' => $this->text()->null(),
            'payload'       => $this->longText()->null(),
            'mappings'      => $this->longText()->null(),
            'dateCreated'   => $this->dateTime()->notNull(),
            'dateUpdated'   => $this->dateTime()->notNull(),
            'uid'           => $this->uid(),
        ]);

        $this->createIndex(null, Table::LOG_ITEMS, ['logId', 'action']);
        $this->createIndex(null, Table::LOG_ITEMS, ['elementId']);
        $this->createIndex(null, Table::LOG_ITEMS, ['action']);

        $this->addForeignKey(
            null,
            Table::LOG_ITEMS,
            ['logId'],
            Table::LOGS,
            ['id'],
            'CASCADE'
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG_ITEMS);
        $this->dropTableIfExists(Table::LOGS);
        $this->dropTableIfExists(Table::LINKS);

        return true;
    }
}
