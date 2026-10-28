<?php

namespace app\modules\cloud\models;

use app\components\Actor;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\components\CloudPosture;
use app\modules\inventory\components\DevicePosture;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Expression;

class CloudAccountSearch extends Model
{
    public const FILTERS = [
        'all' => 'All',
        'active' => 'In service',
        'mine' => 'Added by me',
        'non-compliant' => 'Non-compliant',
        'not-assessed' => 'Not assessed',
        'archived' => 'Archived',
    ];

    public $q;
    public $filter = 'all';
    public $reference;
    public $name;
    public $provider;
    public $environment;
    public $owner_id;
    public $status;
    public $criticality;
    /** cloud-check control id (from the control page) */
    public $control;
    /** result for the $control filter: pass | fail | unknown */
    public $result = CloudPosture::RESULT_FAIL;

    public function rules(): array
    {
        return [
            [['q', 'reference', 'name', 'provider', 'environment', 'status', 'criticality'], 'string', 'max' => 100],
            [['owner_id', 'control'], 'integer'],
            ['result', 'in', 'range' => array_keys(DevicePosture::resultLabels())],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function attributeLabels(): array
    {
        return (new CloudAccount())->attributeLabels();
    }

    public function formName(): string
    {
        return '';
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = CloudAccount::find()->with(['owner']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['reference' => SORT_ASC],
                'attributes' => [
                    'reference', 'name', 'provider', 'environment', 'owner_id', 'status', 'updated_at',
                    'criticality' => [
                        'asc' => [new Expression("FIELD(cloud_account.criticality, 'low','medium','high','critical')")],
                        'desc' => [new Expression("FIELD(cloud_account.criticality, 'critical','high','medium','low')")],
                    ],
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
            case 'active':
                $query->active()->inService();
                break;
            case 'mine':
                $query->active()->andWhere(['cloud_account.created_by' => Actor::id()]);
                break;
            case 'non-compliant':
                $query->active()->inService()->nonCompliant();
                break;
            case 'not-assessed':
                $query->active()->inService()->notAssessed();
                break;
            default:
                $query->active();
        }

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or',
                ['like', 'cloud_account.reference', $this->q],
                ['like', 'cloud_account.name', $this->q],
                ['like', 'cloud_account.account_identifier', $this->q],
            ]);
        }

        if ($this->control) {
            $check = CloudChecks::find((int) $this->control);
            if ($check) {
                $query->checkResult($check, $this->result);
            } else {
                $query->andWhere('0=1');
            }
        }

        $query->andFilterWhere(['like', 'cloud_account.reference', $this->reference])
            ->andFilterWhere(['or', ['like', 'cloud_account.name', $this->name], ['like', 'cloud_account.account_identifier', $this->name]])
            ->andFilterWhere([
                'cloud_account.provider' => $this->provider,
                'cloud_account.environment' => $this->environment,
                'cloud_account.owner_id' => $this->owner_id,
                'cloud_account.status' => $this->status,
                'cloud_account.criticality' => $this->criticality,
            ]);

        return $dataProvider;
    }
}
