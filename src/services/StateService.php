<?php

namespace luremo\linkmigrator\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\Db;
use luremo\linkmigrator\models\AuditResult;
use luremo\linkmigrator\models\FieldMapping;
use luremo\linkmigrator\records\FieldMappingRecord;
use luremo\linkmigrator\records\MigrationRecord;

class StateService extends Component
{
    public function getFieldMapping(string $sourceFieldUid): ?FieldMapping
    {
        // Resolve by the stable source field UID, not the reusable handle, so a deleted and
        // recreated field with the same handle cannot inherit the old field's mapping/phase
        // (finding 6).
        $record = FieldMappingRecord::find()
            ->where(['sourceFieldUid' => $sourceFieldUid])
            ->orderBy([
                'targetHandle' => SORT_DESC,
                'preparedAt' => SORT_DESC,
                'dateUpdated' => SORT_DESC,
            ])
            ->one();
        return $record ? $this->toFieldMapping($record) : null;
    }

    /**
     * @return FieldMapping[]
     */
    public function getFieldMappings(?string $fieldHandle = null): array
    {
        $query = FieldMappingRecord::find()->orderBy(['sourceHandle' => SORT_ASC]);
        if ($fieldHandle !== null) {
            $query->where(['sourceHandle' => $fieldHandle]);
        }

        return array_map(fn(FieldMappingRecord $record) => $this->toFieldMapping($record), $query->all());
    }

    public function savePreparedFieldMapping(array $mapping): FieldMapping
    {
        $record = FieldMappingRecord::findOne(['sourceFieldUid' => $mapping['sourceFieldUid']]);
        if (!$record) {
            $record = FieldMappingRecord::findOne(['sourceHandle' => $mapping['sourceHandle']]);
        }
        $record ??= new FieldMappingRecord();
        $record->sourceFieldId = $mapping['sourceFieldId'];
        $record->sourceFieldUid = $mapping['sourceFieldUid'];
        $record->sourceHandle = $mapping['sourceHandle'];
        $record->targetFieldId = $mapping['targetFieldId'];
        $record->targetFieldUid = $mapping['targetFieldUid'];
        $record->targetHandle = $mapping['targetHandle'];
        $record->phase = FieldMapping::PHASE_PREPARED;
        $record->preparedAt = Db::prepareDateForDb(new \DateTimeImmutable());
        $record->save(false);

        return $this->toFieldMapping($record);
    }

    public function markContentMigrated(string $sourceFieldUid, bool $readyToFinalize = true): void
    {
        $record = FieldMappingRecord::findOne(['sourceFieldUid' => $sourceFieldUid]);
        if (!$record) {
            return;
        }

        $record->phase = $readyToFinalize ? FieldMapping::PHASE_READY_TO_FINALIZE : FieldMapping::PHASE_CONTENT_MIGRATED;
        $record->contentMigratedAt = Db::prepareDateForDb(new \DateTimeImmutable());
        $record->save(false);
    }

    public function markFinalized(string $sourceFieldUid): void
    {
        $record = FieldMappingRecord::findOne(['sourceFieldUid' => $sourceFieldUid]);
        if (!$record) {
            return;
        }

        $record->phase = FieldMapping::PHASE_FINALIZED;
        $record->finalizedAt = Db::prepareDateForDb(new \DateTimeImmutable());
        $record->save(false);
    }

    public function migratedMap(string $action, string $sourceFieldUid, array $elements): array
    {
        $ownerIds = [];
        $siteIds = [];

        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface || $element->id === null || $element->siteId === null) {
                continue;
            }

            $ownerIds[(int)$element->id] = true;
            $siteIds[(int)$element->siteId] = true;
        }

        if ($ownerIds === [] || $siteIds === []) {
            return [];
        }

        $records = MigrationRecord::find()
            ->select(['ownerId', 'siteId'])
            ->where([
                'action' => $action,
                'sourceFieldUid' => $sourceFieldUid,
                'status' => 'migrated',
                'ownerId' => array_keys($ownerIds),
                'siteId' => array_keys($siteIds),
            ])
            ->asArray()
            ->all();

        $map = [];
        foreach ($records as $record) {
            $map[$record['ownerId'] . ':' . $record['siteId']] = true;
        }

        return $map;
    }

    public function markMigrated(
        string $action,
        string $fieldHandle,
        string $sourceFieldUid,
        ElementInterface $element,
        array $warnings = [],
        array $backup = [],
        ?string $backupPath = null
    ): void
    {
        $this->saveRecord($action, $fieldHandle, $sourceFieldUid, $element, 'migrated', $warnings, $backup, $backupPath);
    }

    public function markSkipped(string $action, string $fieldHandle, string $sourceFieldUid, ElementInterface $element, string $reason): void
    {
        $this->saveRecord($action, $fieldHandle, $sourceFieldUid, $element, 'skipped', [$reason], [], null);
    }

    public function markWarning(string $action, string $fieldHandle, string $sourceFieldUid, ElementInterface $element, array $warnings, array $backup = []): void
    {
        $this->saveRecord($action, $fieldHandle, $sourceFieldUid, $element, 'warning', $warnings, $backup, null);
    }

    public function markError(
        string $action,
        string $fieldHandle,
        string $sourceFieldUid,
        ElementInterface $element,
        string $reason,
        array $backup = []
    ): void {
        $this->saveRecord($action, $fieldHandle, $sourceFieldUid, $element, 'error', [$reason], $backup, null);
    }

    public function writeBackup(string $action, string $fieldHandle, ElementInterface $element, array $backup): string
    {
        $baseDir = Craft::getAlias('@storage/runtime/link-migrator/backups');
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        $path = sprintf(
            '%s/%s-%s-%s-%s.json',
            $baseDir,
            $action,
            $fieldHandle,
            $element->id,
            $element->siteId
        );

        file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return $path;
    }

    public function summaries(?string $fieldHandle = null): array
    {
        $query = MigrationRecord::find()
            ->select([
                'action',
                'fieldHandle',
                'SUM(CASE WHEN status = "migrated" THEN 1 ELSE 0 END) AS migratedCount',
                'SUM(CASE WHEN status = "skipped" THEN 1 ELSE 0 END) AS skippedCount',
                'SUM(CASE WHEN status = "skipped" AND warningsJson LIKE \'%Empty value.%\' THEN 1 ELSE 0 END) AS skippedEmptyCount',
                'SUM(CASE WHEN status = "skipped" AND warningsJson LIKE \'%Already migrated.%\' THEN 1 ELSE 0 END) AS skippedAlreadyMigratedCount',
                'SUM(CASE WHEN status = "skipped" AND warningsJson LIKE \'%Already a native Link value.%\' THEN 1 ELSE 0 END) AS skippedAlreadyNativeCount',
                'SUM(CASE WHEN status = "skipped" AND warningsJson NOT LIKE \'%Empty value.%\' AND warningsJson NOT LIKE \'%Already migrated.%\' AND warningsJson NOT LIKE \'%Already a native Link value.%\' THEN 1 ELSE 0 END) AS skippedOtherCount',
                'SUM(CASE WHEN status = "warning" THEN 1 ELSE 0 END) AS warningCount',
                'SUM(CASE WHEN status = "error" THEN 1 ELSE 0 END) AS errorCount',
                'SUM(CASE WHEN backupPath IS NOT NULL AND backupPath != "" THEN 1 ELSE 0 END) AS backupCount',
                'MAX(dateUpdated) AS dateUpdated',
            ])
            ->groupBy(['action', 'fieldHandle'])
            ->asArray();

        if ($fieldHandle) {
            $query->andWhere(['fieldHandle' => $fieldHandle]);
        }

        return $query->all();
    }

    public function workflowStatuses(AuditResult $audit, ?string $fieldHandle = null): array
    {
        $mappings = [];
        foreach ($this->getFieldMappings($fieldHandle) as $mapping) {
            $mappings[$mapping->sourceHandle] = $mapping;
        }

        $summaries = [];
        foreach ($this->summaries($fieldHandle) as $summary) {
            $summaries[$summary['action'] . ':' . $summary['fieldHandle']] = $summary;
        }

        $statuses = [];
        foreach ($audit->fields as $fieldAudit) {
            $mapping = $mappings[$fieldAudit->handle] ?? null;
            $content = $summaries['content:' . $fieldAudit->handle] ?? null;
            $statuses[] = [
                'sourceHandle' => $fieldAudit->handle,
                'sourceName' => $fieldAudit->name,
                'targetHandle' => $mapping?->targetHandle,
                'phase' => $mapping?->phase ?? FieldMapping::PHASE_AUDITED,
                'mappingStatus' => $fieldAudit->mapping->status,
                'preparedAt' => $mapping?->preparedAt,
                'contentMigratedAt' => $mapping?->contentMigratedAt,
                'finalizedAt' => $mapping?->finalizedAt,
                'contentSummary' => $content,
            ];
        }

        return $statuses;
    }

    private function toFieldMapping(FieldMappingRecord $record): FieldMapping
    {
        return new FieldMapping([
            'sourceFieldId' => (int)$record->sourceFieldId,
            'sourceFieldUid' => (string)$record->sourceFieldUid,
            'sourceHandle' => (string)$record->sourceHandle,
            'targetFieldId' => $record->targetFieldId !== null ? (int)$record->targetFieldId : null,
            'targetFieldUid' => $record->targetFieldUid ? (string)$record->targetFieldUid : null,
            'targetHandle' => $record->targetHandle ? (string)$record->targetHandle : null,
            'phase' => (string)$record->phase,
            'preparedAt' => $record->preparedAt,
            'contentMigratedAt' => $record->contentMigratedAt,
            'finalizedAt' => $record->finalizedAt,
        ]);
    }

    private function saveRecord(
        string $action,
        string $fieldHandle,
        string $sourceFieldUid,
        ElementInterface $element,
        string $status,
        array $warnings,
        array $backup,
        ?string $backupPath
    ): void {
        // Identity is the stable source field UID, not the reusable handle (finding 6).
        $record = MigrationRecord::findOne([
            'action' => $action,
            'sourceFieldUid' => $sourceFieldUid,
            'ownerId' => $element->id,
            'siteId' => $element->siteId,
        ]) ?? new MigrationRecord();

        if (!$this->shouldReplaceStatus($record->status, $status)) {
            return;
        }

        $record->action = $action;
        $record->fieldHandle = $fieldHandle;
        $record->sourceFieldUid = $sourceFieldUid;
        $record->ownerId = $element->id;
        $record->siteId = $element->siteId;
        $record->ownerUid = $element->uid;
        $record->status = $status;
        $record->warningsJson = json_encode(array_values($warnings), JSON_UNESCAPED_SLASHES);
        $record->backupJson = $backup ? json_encode($backup, JSON_UNESCAPED_SLASHES) : null;
        $record->backupPath = $backupPath;
        $record->save(false);
    }

    private function shouldReplaceStatus(?string $existingStatus, string $incomingStatus): bool
    {
        if ($existingStatus === null || $existingStatus === '') {
            return true;
        }

        // The only non-authoritative outcome is a "skipped" status, which a re-run records
        // for units it deliberately leaves alone (e.g. "Already migrated."). It must never
        // downgrade a confirmed migration. Every other status reflects what the current run
        // actually observed for this unit, so the latest outcome wins — including a later
        // warning or error that supersedes an earlier success (finding 7).
        if ($incomingStatus === 'skipped' && $existingStatus === 'migrated') {
            return false;
        }

        return true;
    }
}
