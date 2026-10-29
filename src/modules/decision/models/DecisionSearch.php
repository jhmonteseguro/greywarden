<?php

namespace app\modules\decision\models;

use app\components\Actor;
use app\modules\attack\models\AttackMitigation;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Query;

class DecisionSearch extends Model
{
    public const FILTERS = [
        'all' => 'All',
        'mine' => 'Logged by me',
        'proposed' => 'Awaiting approval',
        'review-overdue' => 'Review overdue',
        'archived' => 'Archived',
    ];

    public $q;
    public $filter = 'all';
    public $reference;
    public $title;
    public $category_id;
    public $status;
    public $decision_makers;
    public $decision_date;
    /** ATT&CK id prefix or name, e.g. M1032 */
    public $mitigation;

    public function rules(): array
    {
        return [
            [['q', 'mitigation', 'reference', 'title', 'status', 'decision_makers'], 'string', 'max' => 100],
            ['category_id', 'integer'],
            ['decision_date', 'date', 'format' => 'php:Y-m-d'],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function attributeLabels(): array
    {
        return (new Decision())->attributeLabels() + ['mitigation' => 'ATT&CK'];
    }

    public function formName(): string
    {
        return '';
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = Decision::find()->with(['category', 'mitigations']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['reference' => SORT_DESC],
                'attributes' => [
                    'reference' => ['asc' => ['decision.id' => SORT_ASC], 'desc' => ['decision.id' => SORT_DESC]],
                    'title', 'status', 'category_id', 'decision_makers',
                    'decision_date' => ['asc' => ['decision_date' => SORT_ASC, 'decision.id' => SORT_ASC], 'desc' => ['decision_date' => SORT_DESC, 'decision.id' => SORT_DESC]],
                    'review_date', 'updated_at',
                ],
            ],
        ]);

        $this->load($params);
        if (!$this->validate()) {
            $query->where('0=1');
            return $dataProvider;
        }

        switch ($this->filter ?: 'all') {
            case 'archived':
                $query->archived();
                break;
            case 'mine':
                $query->active()->andWhere(['decision.created_by' => Actor::id()]);
                break;
            case 'proposed':
                $query->active()->andWhere(['decision.status' => Decision::STATUS_PROPOSED]);
                break;
            case 'review-overdue':
                $query->active()->andWhere(['decision.status' => Decision::REVIEWABLE_STATUSES])
                    ->andWhere(['<', 'decision.review_date', date('Y-m-d')]);
                break;
            default:
                $query->active();
        }

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or',
                ['like', 'decision.reference', $this->q],
                ['like', 'decision.title', $this->q],
                ['like', 'decision.decision_text', $this->q],
                ['like', 'decision.context', $this->q],
            ]);
        }

        if ($this->mitigation !== null && $this->mitigation !== '') {
            $query->andWhere(['decision.id' => (new Query())->select('decision_id')->from('decision_attack_mitigation')
                ->where(['mitigation_id' => AttackMitigation::idsMatching($this->mitigation) ?: [0]])]);
        }

        $query->andFilterWhere(['like', 'decision.reference', $this->reference])
            ->andFilterWhere(['like', 'decision.title', $this->title])
            ->andFilterWhere(['like', 'decision.decision_makers', $this->decision_makers])
            ->andFilterWhere([
                'decision.category_id' => $this->category_id,
                'decision.status' => $this->status,
                'decision.decision_date' => $this->decision_date,
            ]);

        return $dataProvider;
    }
}
