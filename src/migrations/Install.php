<?php

namespace TDM\Influx\migrations;

use craft\db\Migration;
use TDM\Influx\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Table::LOGS, [
            'id'           => $this->primaryKey(),
            'feedHandle'   => $this->string(100)->notNull(),
            'trigger'      => $this->string(30)->notNull(),    // console | cp | element | queue
            'siteHandle'   => $this->string(100)->null(),
            'status'       => $this->string(20)->notNull(),    // running | ok | error
            'itemsSeen'    => $this->integer()->defaultValue(0),
            'itemsCreated' => $this->integer()->defaultValue(0),
            'itemsUpdated' => $this->integer()->defaultValue(0),
            'itemsUnchanged' => $this->integer()->defaultValue(0),
            'itemsSkipped' => $this->integer()->defaultValue(0),
            'itemsDeleted' => $this->integer()->defaultValue(0),
            'startedAt'    => $this->dateTime()->notNull(),
            'finishedAt'   => $this->dateTime()->null(),
            'error'        => $this->text()->null(),
            'dateCreated'  => $this->dateTime()->notNull(),
            'dateUpdated'  => $this->dateTime()->notNull(),
            'uid'          => $this->uid(),
        ]);

        $this->createIndex(null, Table::LOGS, ['feedHandle']);
        $this->createIndex(null, Table::LOGS, ['startedAt']);

        $this->createTable(Table::LOG_ITEMS, [
            'id'         => $this->primaryKey(),
            'logId'      => $this->integer()->notNull(),
            'elementId'  => $this->integer()->null(),
            'matchValue' => $this->string(255)->null(),
            'action'     => $this->string(30)->notNull(), // created|updated|unchanged|skipped|disabled|deleted|deleted-for-site|error
            'message'    => $this->text()->null(),
            'payload'    => $this->longText()->null(),    // raw remote item JSON (optional)
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'        => $this->uid(),
        ]);

        $this->createIndex(null, Table::LOG_ITEMS, ['logId']);
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
        return true;
    }
}
