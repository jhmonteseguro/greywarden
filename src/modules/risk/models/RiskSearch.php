<?php

namespace app\modules\risk\models;

use app\components\Actor;
use app\helpers\RiskMatrix;
use app\modules\attack\models\AttackTechnique;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Query;

/**
 * Search / filter model for the risk register grid.
 */
class RiskSearch extends Model
{
    public const FILTERS = [
        'all' => 'All',
        'mine' => 'My risks',
        'open' => 'Open',
        'review-overdue' => 'Review overdue',
        'treatment-overdue' => 'Treatment overdue',
        'control-issues' => 'Control issues',
        'archived' => 'Archived',
    ];

    public $q;
    public $filter = 'all';
    public $reference;
    public $title;
    public $category_id;
    public $owner_id;
    public $status;
    public $treatment;
    public $inherent_rating;
    public $residual_rating;
    public $target_rating;
    public $inherent_likelihood;
    public $inherent_impact;
    public $residual_likelihood;
    public $residual_impact;
    public $target_likelihood;
    public $target_impact;
    /** ATT&CK id prefix or name, e.g. T1566 */
    public $technique;

    public function rules(): array
    {
        return [
            [['q', 'technique', 'reference', 'title', 'status', 'treatment', 'inherent_rating', 'residual_rating', 'target_rating'], 'string', 'max' => 100],
            [['category_id', 'owner_id', 'inherent_likelihood', 'inherent_impact', 'residual_likelihood', 'residual_impact', 'target_likelihood', 'target_impact'], 'integer'],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function attributeLabels(): array
    {
        return (new Risk())->attributeLabels() + ['inherent_rating' => 'Inherent', 'residual_rating' => 'Residual', 'target_rating' => 'Target', 'technique' => 'ATT&CK'];
    }

    public function formName(): string
    {
        return '';
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = Risk::find()->with(['owner', 'category', 'techniques', 'controls']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['reference' => SORT_DESC],
                'attributes' => [
                    'reference' => ['asc' => ['risk.id' => SORT_ASC], 'desc' => ['risk.id' => SORT_DESC]],
                    'title', 'status', 'treatment', 'owner_id', 'category_id',
                    'inherent_rating' => ['asc' => ['inherent_score' => SORT_ASC], 'desc' => ['inherent_score' => SORT_DESC]],
                    'residual_rating' => ['asc' => ['residual_score' => SORT_ASC], 'desc' => ['residual_score' => SORT_DESC]],
                    'target_rating' => ['asc' => ['target_score' => SORT_ASC], 'desc' => ['target_score' => SORT_DESC]],
                    'next_review_date', 'target_treatment_date', 'date_identified', 'updated_at',
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
                $query->active()->andWhere(['risk.owner_id' => Actor::id()]);
                break;
            case 'open':
                $query->active()->open();
                break;
            case 'review-overdue':
                $query->active()->reviewOverdue($today);
                break;
            case 'treatment-overdue':
                $query->active()->treatmentOverdue($today);
                break;
            case 'control-issues':
                $query->active()->controlIssues($today);
                break;
            default:
                $query->active();
        }

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or',
                ['like', 'risk.reference', $this->q],
                ['like', 'risk.title', $this->q],
                ['like', 'risk.description', $this->q],
                ['like', 'risk.scope', $this->q],
            ]);
        }

        if ($this->technique !== null && $this->technique !== '') {
            $query->andWhere(['risk.id' => (new Query())->select('risk_id')->from('risk_attack_technique')
                ->where(['technique_id' => AttackTechnique::idsMatching($this->technique) ?: [0]])]);
        }

        $query->andFilterWhere(['like', 'risk.reference', $this->reference])
            ->andFilterWhere(['like', 'risk.title', $this->title])
            ->andFilterWhere([
                'risk.category_id' => $this->category_id,
                'risk.owner_id' => $this->owner_id,
                'risk.status' => $this->status,
                'risk.treatment' => $this->treatment,
                'risk.inherent_likelihood' => $this->inherent_likelihood,
                'risk.inherent_impact' => $this->inherent_impact,
                'risk.residual_likelihood' => $this->residual_likelihood,
                'risk.residual_impact' => $this->residual_impact,
                'risk.target_likelihood' => $this->target_likelihood,
                'risk.target_impact' => $this->target_impact,
            ]);

        foreach (['inherent' => $this->inherent_rating, 'residual' => $this->residual_rating, 'target' => $this->target_rating] as $kind => $rating) {
            if ($rating && ($range = RiskMatrix::scoreRange($rating))) {
                $query->andWhere(['between', "risk.{$kind}_score", $range[0], $range[1]]);
            }
        }

        return $dataProvider;
    }
}
