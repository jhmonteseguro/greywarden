<?php

namespace app\models;

use yii\db\ActiveQuery;

/**
 * Base query for domain records (soft delete aware).
 */
class DomainQuery extends ActiveQuery
{
    protected function col(string $column): string
    {
        /** @var DomainRecord $class */
        $class = $this->modelClass;
        return $class::tableName() . '.' . $column;
    }

    /** Not archived */
    public function active(): static
    {
        return $this->andWhere([$this->col('archived_at') => null]);
    }

    public function archived(): static
    {
        return $this->andWhere(['not', [$this->col('archived_at') => null]]);
    }
}
