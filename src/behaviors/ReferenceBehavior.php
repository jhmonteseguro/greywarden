<?php

namespace app\behaviors;

use app\components\ReferenceGenerator;
use yii\base\Behavior;
use yii\db\BaseActiveRecord;

/**
 * Fills a reference attribute (e.g. RSK-0001) on insert when it is empty.
 */
class ReferenceBehavior extends Behavior
{
    public string $attribute = 'reference';
    public string $prefix = 'REF';
    public int $padding = 4;

    public function events(): array
    {
        return [BaseActiveRecord::EVENT_BEFORE_INSERT => 'assign'];
    }

    public function assign(): void
    {
        /** @var \yii\db\ActiveRecord $owner */
        $owner = $this->owner;
        $value = trim((string) $owner->getAttribute($this->attribute));
        if ($value === '') {
            $owner->setAttribute(
                $this->attribute,
                ReferenceGenerator::next($owner::tableName(), $this->attribute, $this->prefix, $this->padding, $owner::getDb())
            );
        }
    }
}
