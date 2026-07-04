<?php

namespace luremo\linkmigrator\models;

use yii\base\Model;

class CutoverResult extends Model
{
    public array $finalized = [];
    public array $skipped = [];
    public array $errors = [];

    public function hasErrors($attribute = null): bool
    {
        return $this->errors !== [];
    }
}
