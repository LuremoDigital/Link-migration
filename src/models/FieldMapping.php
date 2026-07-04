<?php

namespace luremo\linkmigrator\models;

use yii\base\Model;

class FieldMapping extends Model
{
    public const PHASE_AUDITED = 'audited';
    public const PHASE_PREPARED = 'prepared';
    public const PHASE_CONTENT_MIGRATED = 'contentMigrated';
    public const PHASE_READY_TO_FINALIZE = 'readyToFinalize';
    public const PHASE_FINALIZED = 'finalized';

    public int $sourceFieldId;
    public string $sourceFieldUid;
    public string $sourceHandle;
    public ?int $targetFieldId = null;
    public ?string $targetFieldUid = null;
    public ?string $targetHandle = null;
    public string $phase = self::PHASE_AUDITED;
    public ?string $preparedAt = null;
    public ?string $contentMigratedAt = null;
    public ?string $finalizedAt = null;

    public function isPrepared(): bool
    {
        return in_array($this->phase, [
            self::PHASE_PREPARED,
            self::PHASE_CONTENT_MIGRATED,
            self::PHASE_READY_TO_FINALIZE,
            self::PHASE_FINALIZED,
        ], true);
    }

    public function isContentReady(): bool
    {
        return in_array($this->phase, [
            self::PHASE_READY_TO_FINALIZE,
            self::PHASE_FINALIZED,
        ], true);
    }
}
