<?php

namespace lm2k\hypertolink\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * Re-keys migration evidence by source field UID instead of handle only (finding 6).
 *
 * Handle-only identity lets deleted/recreated fields and handle reuse contaminate migration
 * status. The source field UID is stable across handle changes and unique per field, so it is
 * the correct identity for the per-element skip/evidence records. `fieldHandle` is retained for
 * human-readable reporting.
 */
class m260630_000000_migration_source_field_uid extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%linkmigrator_migrations}}';

        if (!$this->db->columnExists($table, 'sourceFieldUid')) {
            $this->addColumn($table, 'sourceFieldUid', $this->uid()->after('fieldHandle'));
        }

        // Best-effort backfill: map each existing record's handle to a known source field UID.
        $mappings = (new Query())
            ->select(['sourceHandle', 'sourceFieldUid'])
            ->from('{{%linkmigrator_fieldmappings}}')
            ->all($this->db);

        foreach ($mappings as $mapping) {
            if (empty($mapping['sourceFieldUid']) || empty($mapping['sourceHandle'])) {
                continue;
            }

            $this->update(
                $table,
                ['sourceFieldUid' => $mapping['sourceFieldUid']],
                ['fieldHandle' => $mapping['sourceHandle']],
                [],
                false
            );
        }

        $this->dropHandleUniqueIndex($table);
        $this->createIndex(null, $table, ['action', 'sourceFieldUid', 'ownerId', 'siteId'], true);

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%linkmigrator_migrations}}';

        if ($this->db->columnExists($table, 'sourceFieldUid')) {
            $this->dropColumn($table, 'sourceFieldUid');
        }

        return true;
    }

    private function dropHandleUniqueIndex(string $table): void
    {
        $schema = $this->db->getSchema();
        $rawTableName = $schema->getRawTableName($table);
        $target = ['action', 'fieldHandle', 'ownerId', 'siteId'];

        foreach ($schema->getTableIndexes($rawTableName) as $index) {
            if (!$index->isUnique || $index->isPrimary) {
                continue;
            }

            $columns = $index->columnNames;
            sort($columns);
            $expected = $target;
            sort($expected);

            if ($columns === $expected) {
                $this->dropIndex($index->name, $table);
            }
        }
    }
}
