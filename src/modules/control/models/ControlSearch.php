<?php

namespace app\modules\control\models;

use app\components\Actor;
use app\modules\attack\models\AttackMitigation;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Query;

class ControlSearch extends Model
{
    public const FILTERS = [
        'all' => 'All',
        'mine' => 'Owned by me',
        'planned' => 'Planned',
        'implemented' => 'Implemented',
        'test-due-soon' => 'Test due soon',
        'test-overdue' => 'Test overdue',
        'weak' => 'Not effective',
        'not-tested' => 'Never tested',
        'target-overdue' => 'Past target date',
        'device-checks' => 'Device checks',
        'cloud-checks' => 'Cloud checks',
        'retired' => 'Retired',
        'archived' => 'Archived',
    ];

    public $q;
    public $filter = 'all';
    public $reference;
    public $title;
    public $control_type;
    public $nature;
    public $owner_id;
    public $status;
    public $effectiveness;
    /** ISO 27001 / Cyber Essentials id or title, e.g. A.8.7 or CE-UA-4 */
    public $requirement;
    /** ATT&CK id or name, e.g. M1032 */
    public $mitigation;

    public function rules(): array
    {
        return [
            [['q', 'reference', 'title', 'control_type', 'nature', 'status', 'effectiveness', 'requirement', 'mitigation'], 'string', 'max' => 100],
            ['owner_id', 'integer'],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function attributeLabels(): array
    {
        return (new Control())->attributeLabels() + ['requirement' => 'Frameworks', 'mitigation' => 'ATT&CK'];
    }

    public function formName(): string
    {
        return '';
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = Control::find()->with(['owner', 'requirements']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['reference' => SORT_ASC],
                'attributes' => [
                    'reference' => ['asc' => ['control.id' => SORT_ASC], 'desc' => ['control.id' => SORT_DESC]],
                    'title', 'control_type', 'nature', 'owner_id', 'status', 'target_date', 'last_tested_on', 'updated_at',
                    'effectiveness' => [
                        'asc' => [new \yii\db\Expression("FIELD(control.effectiveness, 'ineffective','partial','not_tested','effective')")],
                        'desc' => [new \yii\db\Expression("FIELD(control.effectiveness, 'effective','not_tested','partial','ineffective')")],
                    ],
                    'next_test_due' => ['asc' => [new \yii\db\Expression('control.next_test_due IS NULL'), 'control.next_test_due' => SORT_ASC], 'desc' => ['control.next_test_due' => SORT_DESC]],
                ],
            ],
        ]);

        $this->load($params);
        if (!$this->validate()) {
            $query->where('0=1');
            return $dataProvider;
        }

        $today = date('Y-m-d');
        switch ($this->filter ?: 'all') {
            case 'archived':
                $query->archived();
                break;
            case 'mine':
                $query->active()->andWhere(['control.owner_id' => Actor::id()]);
                break;
            case 'planned':
                $query->active()->andWhere(['control.status' => Control::PENDING_STATUSES]);
                break;
            case 'implemented':
                $query->active()->implemented();
                break;
            case 'test-due-soon':
                $query->active()->testDueSoon($today);
                $dataProvider->sort->defaultOrder = ['next_test_due' => SORT_ASC];
                break;
            case 'test-overdue':
                $query->active()->testOverdue($today);
                break;
            case 'weak':
                $query->active()->weakResult();
                break;
            case 'not-tested':
                $query->active()->implemented()->andWhere(['control.effectiveness' => Control::EFFECTIVENESS_NOT_TESTED]);
                break;
            case 'target-overdue':
                $query->active()->targetOverdue($today);
                break;
            case 'device-checks':
                $query->active()->deviceChecks();
                break;
            case 'cloud-checks':
                $query->active()->cloudChecks();
                break;
            case 'retired':
                $query->active()->andWhere(['control.status' => Control::STATUS_RETIRED]);
                break;
            default:
                $query->active();
        }

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or',
                ['like', 'control.reference', $this->q],
                ['like', 'control.title', $this->q],
                ['like', 'control.description', $this->q],
                ['like', 'control.implementation', $this->q],
            ]);
        }

        if ($this->requirement !== null && $this->requirement !== '') {
            $query->andWhere(['control.id' => (new Query())->select('control_id')->from('control_framework_requirement')
                ->where(['requirement_id' => FrameworkRequirement::idsMatching($this->requirement) ?: [0]])]);
        }
        if ($this->mitigation !== null && $this->mitigation !== '') {
            $query->andWhere(['control.id' => (new Query())->select('control_id')->from('control_attack_mitigation')
                ->where(['mitigation_id' => AttackMitigation::idsMatching($this->mitigation) ?: [0]])]);
        }

        $query->andFilterWhere(['like', 'control.reference', $this->reference])
            ->andFilterWhere(['like', 'control.title', $this->title])
            ->andFilterWhere([
                'control.control_type' => $this->control_type,
                'control.nature' => $this->nature,
                'control.owner_id' => $this->owner_id,
                'control.status' => $this->status,
                'control.effectiveness' => $this->effectiveness,
            ]);

        return $dataProvider;
    }
}
