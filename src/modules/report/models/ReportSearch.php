<?php

namespace app\modules\report\models;

use app\modules\report\components\ReportPeriod;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;

class ReportSearch extends Model
{
    public const FILTERS = [
        'all' => 'All',
        'draft' => 'Drafts',
        'approved' => 'Approved',
        'archived' => 'Archived',
    ];

    public $q;
    public $filter = 'all';
    public $reference;
    public $title;
    public $period_type;
    public $status;
    public $rag;

    public function rules(): array
    {
        return [
            [['q', 'reference', 'title'], 'string', 'max' => 100],
            ['period_type', 'in', 'range' => array_keys(ReportPeriod::typeOptions())],
            ['status', 'in', 'range' => array_keys(Report::statusOptions())],
            ['rag', 'in', 'range' => ['green', 'amber', 'red']],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function attributeLabels(): array
    {
        return (new Report())->attributeLabels() + ['period_start' => 'Period'];
    }

    public function formName(): string
    {
        return '';
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = Report::find()->with(['approvedBy']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['period_start' => SORT_DESC],
                'attributes' => [
                    'reference' => ['asc' => ['report.id' => SORT_ASC], 'desc' => ['report.id' => SORT_DESC]],
                    'period_start' => ['asc' => ['period_end' => SORT_ASC, 'period_start' => SORT_ASC, 'report.id' => SORT_ASC],
                        'desc' => ['period_end' => SORT_DESC, 'period_start' => SORT_DESC, 'report.id' => SORT_DESC]],
                    'title', 'period_type', 'status', 'rag', 'approved_at', 'generated_at',
                ],
            ],
        ]);

        $this->load($params);
        if (!$this->validate()) {
            $query->where('0=1');
            return $dataProvider;
        }

        match ($this->filter ?: 'all') {
            'archived' => $query->archived(),
            'draft' => $query->active()->andWhere(['report.status' => Report::STATUS_DRAFT]),
            'approved' => $query->active()->andWhere(['report.status' => Report::STATUS_APPROVED]),
            default => $query->active(),
        };

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or', ['like', 'report.reference', $this->q], ['like', 'report.title', $this->q], ['like', 'report.management_summary', $this->q]]);
        }
        $query->andFilterWhere(['like', 'report.reference', $this->reference])
            ->andFilterWhere(['like', 'report.title', $this->title])
            ->andFilterWhere(['report.period_type' => $this->period_type, 'report.status' => $this->status, 'report.rag' => $this->rag]);

        return $dataProvider;
    }
}
