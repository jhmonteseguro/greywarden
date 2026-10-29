<?php

namespace app\modules\supplier\models;

use app\components\Actor;
use app\modules\inventory\components\DevicePosture;
use app\modules\supplier\components\SupplierChecks;
use app\modules\supplier\components\SupplierPosture;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Expression;

class SupplierSearch extends Model
{
    public const FILTERS = [
        'all' => 'All',
        'active' => 'In service',
        'onboarding' => 'Onboarding',
        'tier-1' => 'Tier 1',
        'mine' => 'I own',
        'non-compliant' => 'Non-compliant',
        'not-assessed' => 'Not assessed',
        'review-overdue' => 'Review overdue',
        'assurance' => 'Assurance expiring',
        'renewals' => 'Notice period closing',
        'archived' => 'Archived',
    ];

    public $q;
    public $filter = 'all';
    public $reference;
    public $name;
    public $category_id;
    public $owner_id;
    public $status;
    public $tier;
    public $criticality;
    public $access_level;
    /** supplier-check control id (from the control page) */
    public $control;
    /** result for the $control filter: pass | fail | unknown */
    public $result = SupplierPosture::RESULT_FAIL;

    public function rules(): array
    {
        return [
            [['q', 'reference', 'name', 'status', 'criticality', 'access_level'], 'string', 'max' => 100],
            [['category_id', 'owner_id', 'control'], 'integer'],
            ['tier', 'in', 'range' => array_map('strval', Supplier::TIERS)],
            ['result', 'in', 'range' => array_keys(DevicePosture::resultLabels())],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function attributeLabels(): array
    {
        return (new Supplier())->attributeLabels();
    }

    public function formName(): string
    {
        return '';
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = Supplier::find()->with(['owner', 'category', 'assurances']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['tier' => SORT_ASC, 'name' => SORT_ASC],
                'attributes' => [
                    'reference', 'name', 'category_id', 'owner_id', 'status', 'tier', 'contract_end', 'next_review_date', 'updated_at',
                    'criticality' => [
                        'asc' => [new Expression("FIELD(supplier.criticality, 'low','medium','high','critical')")],
                        'desc' => [new Expression("FIELD(supplier.criticality, 'critical','high','medium','low')")],
                    ],
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
            case 'active':
                $query->active()->inService();
                break;
            case 'onboarding':
                $query->active()->onboarding();
                break;
            case 'tier-1':
                $query->active()->andWhere(['<>', 'supplier.status', Supplier::STATUS_TERMINATED])->andWhere(['supplier.tier' => 1]);
                break;
            case 'mine':
                $query->active()->andWhere(['supplier.owner_id' => Actor::id()]);
                break;
            case 'non-compliant':
                $query->active()->inService()->nonCompliant($today);
                break;
            case 'not-assessed':
                $query->active()->inService()->notAssessed($today);
                break;
            case 'review-overdue':
                $query->active()->reviewOverdue($today);
                $dataProvider->sort->defaultOrder = ['next_review_date' => SORT_ASC];
                break;
            case 'assurance':
                $query->active()->inService()->assuranceDue($today);
                break;
            case 'renewals':
                $query->active()->renewalDue($today);
                $dataProvider->sort->defaultOrder = ['contract_end' => SORT_ASC];
                break;
            default:
                $query->active();
        }

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or',
                ['like', 'supplier.reference', $this->q],
                ['like', 'supplier.name', $this->q],
                ['like', 'supplier.legal_name', $this->q],
                ['like', 'supplier.website', $this->q],
                ['like', 'supplier.services', $this->q],
            ]);
        }

        if ($this->control) {
            $check = SupplierChecks::find((int) $this->control);
            if ($check) {
                $query->checkResult($check, $this->result, $today);
            } else {
                $query->andWhere('0=1');
            }
        }

        $query->andFilterWhere(['like', 'supplier.reference', $this->reference])
            ->andFilterWhere(['or', ['like', 'supplier.name', $this->name], ['like', 'supplier.legal_name', $this->name]])
            ->andFilterWhere([
                'supplier.category_id' => $this->category_id,
                'supplier.owner_id' => $this->owner_id,
                'supplier.status' => $this->status,
                'supplier.tier' => $this->tier,
                'supplier.criticality' => $this->criticality,
                'supplier.access_level' => $this->access_level,
            ]);

        return $dataProvider;
    }
}
