<?php

namespace luremo\linkmigrator\migrations;

use craft\db\Migration;

class m260319_000000_add_field_mapping_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%linkmigrator_fieldmappings}}')) {
            return true;
        }

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
        return true;
    }
}
