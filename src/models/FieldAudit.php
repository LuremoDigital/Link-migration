<?php

namespace luremo\linkmigrator\models;

use yii\base\Model;

class FieldAudit extends Model
{
    public int $fieldId;
    public string $uid;
    public string $handle;
    public string $name;
    public bool $multi = false;
    public array $allowedHyperTypes = [];
    public array $customFieldLayouts = [];
    public array $containers = [];
    public MappingDecision $mapping;
    public array $warnings = [];
    public array $rawSettings = [];
}
