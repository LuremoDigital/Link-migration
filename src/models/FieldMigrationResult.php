<?php

namespace luremo\linkmigrator\models;

use yii\base\Model;

class FieldMigrationResult extends Model
{
    public array $migrated = [];
    public array $skipped = [];
    public array $warnings = [];
    public array $errors = [];
    public array $mappings = [];

    public function hasErrors($attribute = null): bool
    {
        return $this->errors !== [];
    }
}
