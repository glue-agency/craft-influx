<?php

namespace GlueAgency\Influx\migrations;

use craft\db\Migration;
use GlueAgency\Influx\db\Table;

class Install extends Migration
{
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
            // Runtime last-run tracking — NOT Project Config. lastRunAt
            // survives log deletion; lastLogId is a soft pointer to the run's
            // log for quick access, nulled when that log is deleted.
            'lastRunAt'   => $this->dateTime()->null(),
            'lastLogId'   => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        $this->createIndex(null, Table::LINKS, ['handle'], true);
        $this->createIndex(null, Table::LINKS, ['uid'], true);
        $this->createIndex(null, Table::LINKS, ['elementType']);

        $this->createTable(Table::LOGS, [
            'id'             => $this->primaryKey(),
            'linkHandle'     => $this->string(100)->notNull(),
            'trigger'        => $this->string(30)->notNull(),    // console | cp | element | queue
            'siteHandle'     => $this->string(100)->null(),
            'offsetHandle'   => $this->string(100)->null(),      // sliding-window preset the run used
            'elementId'      => $this->integer()->null(),        // resource a single-element run was triggered for
            'status'         => $this->string(20)->notNull(),    // running | ok | error
            'itemsSeen'      => $this->integer()->defaultValue(0),
            'itemsCreated'   => $this->integer()->defaultValue(0),
            'itemsUpdated'   => $this->integer()->defaultValue(0),
            'itemsUnchanged' => $this->integer()->defaultValue(0),
            'itemsSkipped'   => $this->integer()->defaultValue(0),
            'itemsDeleted'   => $this->integer()->defaultValue(0),
            'itemsDisabled'  => $this->integer()->defaultValue(0),
            'startedAt'      => $this->dateTime()->notNull(),
            'finishedAt'     => $this->dateTime()->null(),
            'error'          => $this->text()->null(),
            'dateCreated'    => $this->dateTime()->notNull(),
            'dateUpdated'    => $this->dateTime()->notNull(),
            'uid'            => $this->uid(),
        ]);

        $this->createIndex(null, Table::LOGS, ['linkHandle']);
        $this->createIndex(null, Table::LOGS, ['startedAt']);
        // errorLogCount() (the CP nav badge) filters on status every page load.
        $this->createIndex(null, Table::LOGS, ['status']);

        $this->createTable(Table::LOG_ITEMS, [
            'id'            => $this->primaryKey(),
            'logId'         => $this->integer()->notNull(),
            'elementId'     => $this->integer()->null(),
            'matchValue'    => $this->text()->null(),
            'action'        => $this->string(30)->notNull(), // created|updated|unchanged|skipped|disabled|deleted|deleted-for-site|error
            'message'       => $this->text()->null(),
            'fieldErrors'   => $this->text()->null(),        // {handle: message} for fields whose strategy threw
            'changedFields' => $this->text()->null(),        // JSON list of mapping handles that changed in this run
            'payload'       => $this->longText()->null(),    // raw remote item JSON (optional)
            'dateCreated'   => $this->dateTime()->notNull(),
            'dateUpdated'   => $this->dateTime()->notNull(),
            'uid'           => $this->uid(),
        ]);

        // Composite: covers logId-only lookups via its leftmost prefix, and the
        // per-action counts/filters on the log detail view.
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
