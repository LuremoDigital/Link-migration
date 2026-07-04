<?php

namespace lm2k\hypertolink\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%linkmigrator_migrations}}', [
            'id' => $this->primaryKey(),
            'action' => $this->string(32)->notNull(),
            'fieldHandle' => $this->string()->notNull(),
            'sourceFieldUid' => $this->uid(),
            'ownerId' => $this->integer(),
            'ownerUid' => $this->uid(),
            'siteId' => $this->integer(),
            'status' => $this->string(32)->notNull(),
            'warningsJson' => $this->text(),
            'backupJson' => $this->longText(),
            'backupPath' => $this->string(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Identity is the stable source field UID, not the reusable handle (finding 6).
        $this->createIndex(
            null,
            '{{%linkmigrator_migrations}}',
            ['action', 'sourceFieldUid', 'ownerId', 'siteId'],
            true
        );

        $this->createTable('{{%linkmigrator_fieldmappings}}', [
            'id' => $this->primaryKey(),
            'sourceFieldId' => $this->integer()->notNull(),
            'sourceFieldUid' => $this->uid()->notNull(),
            'sourceHandle' => $this->string()->notNull(),
            'targetFieldId' => $this->integer(),
            'targetFieldUid' => $this->uid(),
            'targetHandle' => $this->string(),
            'phase' => $this->string(32)->notNull()->defaultValue('audited'),
            'preparedAt' => $this->dateTime(),
            'contentMigratedAt' => $this->dateTime(),
            'finalizedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%linkmigrator_fieldmappings}}', ['sourceFieldUid'], true);
        $this->createIndex(null, '{{%linkmigrator_fieldmappings}}', ['sourceHandle'], true);
        $this->createIndex(null, '{{%linkmigrator_fieldmappings}}', ['targetFieldUid'], false);
        $this->createIndex(null, '{{%linkmigrator_fieldmappings}}', ['targetHandle'], false);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%linkmigrator_fieldmappings}}');
        $this->dropTableIfExists('{{%linkmigrator_migrations}}');
        return true;
    }
}
