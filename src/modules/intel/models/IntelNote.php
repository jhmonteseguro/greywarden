<?php

namespace app\modules\intel\models;

use app\components\Actor;
use app\models\User;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * Analyst note on an investigated IP / ASN. Notes are append-only, like the incident timeline.
 *
 * @property int $id
 * @property int $indicator_id
 * @property string $body
 * @property string $created_at
 * @property string $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property-read IntelIndicator $indicator
 * @property-read User|null $creator
 */
class IntelNote extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'intel_note';
    }

    public function behaviors(): array
    {
        return [
            'timestamp' => ['class' => TimestampBehavior::class, 'value' => static fn() => Actor::now()],
            'blameable' => ['class' => BlameableBehavior::class, 'value' => static fn() => Actor::id()],
        ];
    }

    public function rules(): array
    {
        return [
            ['body', 'trim'],
            [['indicator_id', 'body'], 'required'],
            ['body', 'string', 'max' => 10000],
            ['indicator_id', 'exist', 'targetClass' => IntelIndicator::class, 'targetAttribute' => 'id'],
        ];
    }

    public function attributeLabels(): array
    {
        return ['body' => 'Note'];
    }

    public function getIndicator()
    {
        return $this->hasOne(IntelIndicator::class, ['id' => 'indicator_id']);
    }

    public function getCreator()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }
}
