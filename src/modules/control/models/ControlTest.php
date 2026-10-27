<?php

namespace app\modules\control\models;

use app\components\Actor;
use app\models\Attachment;
use app\models\User;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * One effectiveness test of a control. Saving or deleting a test re-derives
 * the control's last tested date, effectiveness and next due date; those
 * changes land in the control's audit history.
 *
 * @property int $id
 * @property int $control_id
 * @property string $tested_on
 * @property string $result effective | partial | ineffective
 * @property int|null $tested_by_id
 * @property string|null $method
 * @property string|null $notes
 * @property string|null $evidence
 * @property string $created_at
 * @property int|null $created_by
 *
 * @property-read Control $control
 * @property-read User|null $testedBy
 */
class ControlTest extends ActiveRecord
{
    /** @var \yii\web\UploadedFile[] files posted with the "Record a test" form; see validateEvidenceFiles() */
    public array $evidenceFiles = [];

    public static function tableName(): string
    {
        return 'control_test';
    }

    public static function resultOptions(): array
    {
        return array_diff_key(Control::effectivenessOptions(), [Control::EFFECTIVENESS_NOT_TESTED => true]);
    }

    public function behaviors(): array
    {
        return [
            'timestamp' => ['class' => TimestampBehavior::class, 'updatedAtAttribute' => false, 'value' => static fn() => Actor::now()],
            'blameable' => ['class' => BlameableBehavior::class, 'updatedByAttribute' => false, 'value' => static fn() => Actor::id()],
        ];
    }

    public function rules(): array
    {
        return [
            [['method', 'notes', 'evidence'], 'trim'],
            [['tested_on', 'result'], 'required'],
            ['tested_on', 'date', 'format' => 'php:Y-m-d'],
            ['tested_on', 'compare', 'compareValue' => date('Y-m-d'), 'operator' => '<=', 'type' => 'string', 'message' => 'The test date cannot be in the future.'],
            ['result', 'in', 'range' => array_keys(self::resultOptions())],
            ['tested_by_id', 'default', 'value' => Actor::id()],
            ['tested_by_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            ['method', 'string', 'max' => 255],
            ['evidence', 'string', 'max' => 512],
            ['notes', 'string', 'max' => 20000],
            ['evidenceFiles', 'validateEvidenceFiles', 'skipOnEmpty' => true],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'tested_on' => 'Tested on',
            'result' => 'Result',
            'tested_by_id' => 'Tested by',
            'method' => 'Method',
            'notes' => 'Findings',
            'evidence' => 'Evidence',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'method' => 'e.g. "Sampled 10 laptops in Intune", "Phishing simulation", "Restore test".',
            'evidence' => 'Link or location of the evidence, or attach files below.',
        ];
    }

    public function validateEvidenceFiles(string $attribute): void
    {
        foreach ($this->$attribute as $file) {
            if ($problem = Attachment::check($file)) {
                $this->addError($attribute, $problem);
            }
        }
    }

    /**
     * Attaches the validated evidence files to the control, marked as this test's.
     * @return string[] problems storing them
     */
    public function attachEvidenceFiles(): array
    {
        $problems = [];
        foreach ($this->evidenceFiles as $file) {
            $result = Attachment::attach($this->control, $file, null, $this);
            if (is_string($result)) {
                $problems[] = $result;
            }
        }
        return $problems;
    }

    public function getControl()
    {
        return $this->hasOne(Control::class, ['id' => 'control_id']);
    }

    public function getTestedBy()
    {
        return $this->hasOne(User::class, ['id' => 'tested_by_id']);
    }

    public function getResultLabel(): string
    {
        return self::resultOptions()[$this->result] ?? (string) $this->result;
    }

    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);
        $this->syncControl();
    }

    public function afterDelete(): void
    {
        parent::afterDelete();
        $this->syncControl();
    }

    private function syncControl(): void
    {
        $control = Control::findOne($this->control_id);
        if ($control) {
            $control->refreshTesting();
            $control->save(false);
        }
    }
}
