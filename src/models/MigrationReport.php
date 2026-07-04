<?php

namespace luremo\linkmigrator\models;

use yii\base\Model;

class MigrationReport extends Model
{
    public string $runId;
    public string $action;
    public string $reportPath;
    public string $jsonPath;
}
