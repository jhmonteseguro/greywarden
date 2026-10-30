<?php

namespace app\modules\cloud\models;

use app\components\Actor;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\components\CloudConfig;
use app\modules\cloud\components\CloudPosture;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\components\OsLifecycle;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Expression;

class CloudAssetSearch extends Model
{
    public const FILTERS = [
        'all' => 'All',
        'active' => 'In service',
        'mine' => 'Added by me',
        'non-compliant' => 'Non-compliant',
        'not-assessed' => 'Not assessed',
        'internet-facing' => 'Internet-facing',
        'findings' => 'With findings',
        'stale' => 'Not seen recently',
        'unsupported-os' => 'OS end of support',
        'archived' => 'Archived',
    ];

    public $q;
    public $filter = 'all';
    public $reference;
    public $name;
    public $account_id;
    public $provider;
    public $resource_type;
    public $category;
    public $environment;
    public $status;
    public $criticality;
    /** worst finding at least this severe */
    public $finding_severity;
    /** cloud-check control id (from the dashboard chart / control page) */
    public $control;
    /** result for the $control filter: pass | fail | unknown */
    public $result = CloudPosture::RESULT_FAIL;

    public function rules(): array
    {
        return [
            [['q', 'reference', 'name', 'provider', 'resource_type', 'category', 'environment', 'status', 'criticality'], 'string', 'max' => 100],
            ['finding_severity', 'in', 'range' => array_keys(CloudConfig::SEVERITIES)],
            [['account_id', 'control'], 'integer'],
            ['result', 'in', 'range' => array_keys(DevicePosture::resultLabels())],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function attributeLabels(): array
    {
        return ['finding_severity' => 'Findings'] + (new CloudAsset())->attributeLabels() + ['provider' => 'Provider', 'category' => 'Category'];
    }

    public function formName(): string
    {
        return '';
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = CloudAsset::find()->with(['account'])->joinWith(['account a'], false);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['reference' => SORT_DESC],
                'attributes' => [
                    'reference' => ['asc' => ['cloud_asset.reference' => SORT_ASC], 'desc' => ['cloud_asset.reference' => SORT_DESC]],
                    'name' => ['asc' => ['cloud_asset.name' => SORT_ASC], 'desc' => ['cloud_asset.name' => SORT_DESC]],
                    'resource_type' => ['asc' => ['cloud_asset.resource_type' => SORT_ASC], 'desc' => ['cloud_asset.resource_type' => SORT_DESC]],
                    'account_id' => ['asc' => ['a.reference' => SORT_ASC], 'desc' => ['a.reference' => SORT_DESC]],
                    'environment' => ['asc' => ['cloud_asset.environment' => SORT_ASC], 'desc' => ['cloud_asset.environment' => SORT_DESC]],
                    'status' => ['asc' => ['cloud_asset.status' => SORT_ASC], 'desc' => ['cloud_asset.status' => SORT_DESC]],
                    'criticality' => [
                        'asc' => [new Expression("FIELD(cloud_asset.criticality, 'low','medium','high','critical')")],
                        'desc' => [new Expression("FIELD(cloud_asset.criticality, 'critical','high','medium','low')")],
                    ],
                    'last_seen_at' => ['asc' => ['cloud_asset.last_seen_at' => SORT_ASC], 'desc' => ['cloud_asset.last_seen_at' => SORT_DESC]],
                    'finding_severity' => [
                        'asc' => [new Expression("FIELD(cloud_asset.finding_severity, 'low','medium','high','critical')"), 'cloud_asset.finding_count' => SORT_ASC],
                        'desc' => [new Expression("FIELD(cloud_asset.finding_severity, 'critical','high','medium','low') = 0"),
                            new Expression("FIELD(cloud_asset.finding_severity, 'critical','high','medium','low')"), 'cloud_asset.finding_count' => SORT_DESC],
                    ],
                    'updated_at' => ['asc' => ['cloud_asset.updated_at' => SORT_ASC], 'desc' => ['cloud_asset.updated_at' => SORT_DESC]],
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
                $query->active()->andWhere(['cloud_asset.created_by' => Actor::id()]);
                break;
            case 'non-compliant':
                $query->active()->inService()->nonCompliant();
                break;
            case 'not-assessed':
                $query->active()->inService()->notAssessed();
                break;
            case 'internet-facing':
                $query->active()->inService()->internetFacing();
                break;
            case 'findings':
                $query->active()->inService()->withFindings();
                break;
            case 'stale':
                $query->active()->inService()->stale();
                break;
            case 'unsupported-os':
                $query->active()->inService()->osSupport([OsLifecycle::UNSUPPORTED, OsLifecycle::ENDING]);
                break;
            default:
                $query->active();
        }

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or',
                ['like', 'cloud_asset.reference', $this->q],
                ['like', 'cloud_asset.name', $this->q],
                ['like', 'cloud_asset.resource_id', $this->q],
                ['like', 'cloud_asset.region', $this->q],
                ['like', 'cloud_asset.public_ips', $this->q],
                ['like', 'cloud_asset.public_endpoints', $this->q],
                ['like', 'cloud_asset.source_id', $this->q],
                ['like', 'cloud_asset.provider_type', $this->q],
                ['like', 'a.name', $this->q],
                ['like', 'a.account_identifier', $this->q],
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

        if ($this->finding_severity) {
            $query->withFindings($this->finding_severity);
        }

        if ($this->category) {
            $query->andWhere(['cloud_asset.resource_type' => CloudCatalogue::typesIn($this->category) ?: '']);
        }

        $query->andFilterWhere(['like', 'cloud_asset.reference', $this->reference])
            ->andFilterWhere(['or', ['like', 'cloud_asset.name', $this->name], ['like', 'cloud_asset.resource_id', $this->name]])
            ->andFilterWhere([
                'cloud_asset.account_id' => $this->account_id,
                'a.provider' => $this->provider,
                'cloud_asset.resource_type' => $this->resource_type,
                'cloud_asset.environment' => $this->environment,
                'cloud_asset.status' => $this->status,
                'cloud_asset.criticality' => $this->criticality,
            ]);

        return $dataProvider;
    }
}
