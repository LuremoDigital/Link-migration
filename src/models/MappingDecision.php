<?php

namespace luremo\linkmigrator\models;

use yii\base\Model;

class MappingDecision extends Model
{
    public const STATUS_SUPPORTED = 'supported';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_UNSUPPORTED = 'unsupported';

    public string $status = self::STATUS_UNSUPPORTED;
    public array $craftLinkTypes = [];
    public array $advancedFields = [];
    public array $warnings = [];
    public array $unsupportedReasons = [];
    public array $lossyAttributes = [];
    public array $legacyBackupKeys = [];
}
