<?php

namespace luremo\linkmigrator\models;

use yii\base\Model;

class ContentMigrationResult extends Model
{
    private const DETAIL_LIMIT = 250;

    public array $migrated = [];
    public array $skipped = [];
    public array $warnings = [];
    public array $errors = [];
    public array $backups = [];
    public int $migratedCount = 0;
    public int $skippedCount = 0;
    public int $warningCount = 0;
    public int $errorCount = 0;
    public int $backupCount = 0;

    public function addMigrated(array $item): void
    {
        $this->migratedCount++;
        $this->pushSample($this->migrated, $item);
    }

    public function addSkipped(array $item): void
    {
        $this->skippedCount++;
        $this->pushSample($this->skipped, $item);
    }

    public function addWarning(array $item): void
    {
        $this->warningCount++;
        $this->pushSample($this->warnings, $item);
    }

    public function recordError(array $item): void
    {
        $this->errorCount++;
        $this->pushSample($this->errors, $item);
    }

    public function addBackup(string $path): void
    {
        $this->backupCount++;
        $this->pushSample($this->backups, $path);
    }

    public function detailLimit(): int
    {
        return self::DETAIL_LIMIT;
    }

    public function hasErrors($attribute = null): bool
    {
        return $this->errorCount > 0;
    }

    private function pushSample(array &$bucket, mixed $item): void
    {
        if (count($bucket) < self::DETAIL_LIMIT) {
            $bucket[] = $item;
        }
    }
}
