<?php

namespace app\modules\inventory\models;

use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\components\OsLifecycle;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;

class DeviceSearch extends Model
{
    public const FILTERS = [
        'all' => 'All',
        'active' => 'In service',
        'mine' => 'Added by me',
        'non-compliant' => 'Non-compliant',
        'not-assessed' => 'Not assessed',
        'byod' => 'BYOD',
        'stale' => 'Not seen recently',
        'unsupported-os' => 'OS end of support',
        'warranty' => 'Warranty expiring',
        'eol' => 'Past end-of-life',
        'archived' => 'Archived',
    ];

    public $q;
    public $filter = 'all';
    public $asset_tag;
    public $name;
    public $device_type;
    public $ownership;
    public $assigned_to;
    public $department_id;
    public $location_id;
    public $status;
    public $criticality;
    public $data_classification;
    public $os;
    /** device-check control id (from the dashboard chart / control page) */
    public $control;
    /** result for the $control filter: pass | fail | unknown */
    public $result = DevicePosture::RESULT_FAIL;

    public function rules(): array
    {
        return [
            [['q', 'asset_tag', 'name', 'device_type', 'ownership', 'assigned_to', 'status', 'criticality', 'data_classification', 'os'], 'string', 'max' => 100],
            [['department_id', 'location_id', 'control'], 'integer'],
            ['result', 'in', 'range' => array_keys(DevicePosture::resultLabels())],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function attributeLabels(): array
    {
        return (new Device())->attributeLabels();
    }

    public function formName(): string
    {
        return '';
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = Device::find()->with(['department', 'location']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['asset_tag' => SORT_ASC],
                'attributes' => [
                    'asset_tag', 'name', 'device_type', 'assigned_to', 'department_id', 'location_id', 'status',
                    'criticality' => [
                        'asc' => [new \yii\db\Expression("FIELD(device.criticality, 'low','medium','high','critical')")],
                        'desc' => [new \yii\db\Expression("FIELD(device.criticality, 'critical','high','medium','low')")],
                    ],
                    'os', 'last_patched_at', 'last_seen_at', 'warranty_expiry', 'eol_date', 'updated_at',
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
                $query->active()->andWhere(['device.created_by' => \app\components\Actor::id()]);
                break;
            case 'non-compliant':
                $query->active()->inService()->nonCompliant();
                break;
            case 'not-assessed':
                $query->active()->inService()->notAssessed();
                break;
            case 'byod':
                $query->active()->andWhere(['device.ownership' => Device::OWNERSHIP_BYOD]);
                break;
            case 'stale':
                $query->active()->inService()->stale();
                break;
            case 'unsupported-os':
                $query->active()->inService()->osSupport([OsLifecycle::UNSUPPORTED, OsLifecycle::ENDING]);
                break;
            case 'warranty':
                $query->active()->warrantyExpiring();
                break;
            case 'eol':
                $query->active()->andWhere(['not in', 'device.status', ['retired', 'disposed']])->pastEol();
                break;
            default:
                $query->active();
        }

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or',
                ['like', 'device.asset_tag', $this->q],
                ['like', 'device.name', $this->q],
                ['like', 'device.hostname', $this->q],
                ['like', 'device.serial_number', $this->q],
                ['like', 'device.assigned_to', $this->q],
                ['like', 'device.assigned_email', $this->q],
                ['like', 'device.source_id', $this->q],
                ['like', 'device.ip_addresses', $this->q],
                ['like', 'device.model', $this->q],
            ]);
        }

        if ($this->control) {
            $check = DeviceChecks::find((int) $this->control);
            if ($check) {
                $query->checkResult($check, $this->result);
            } else {
                $query->andWhere('0=1');
            }
        }

        $query->andFilterWhere(['like', 'device.asset_tag', $this->asset_tag])
            ->andFilterWhere(['or', ['like', 'device.name', $this->name], ['like', 'device.hostname', $this->name]])
            ->andFilterWhere(['or', ['like', 'device.assigned_to', $this->assigned_to], ['like', 'device.assigned_email', $this->assigned_to]])
            ->andFilterWhere(['like', 'device.os', $this->os])
            ->andFilterWhere([
                'device.device_type' => $this->device_type,
                'device.ownership' => $this->ownership,
                'device.department_id' => $this->department_id,
                'device.location_id' => $this->location_id,
                'device.status' => $this->status,
                'device.criticality' => $this->criticality,
                'device.data_classification' => $this->data_classification,
            ]);

        return $dataProvider;
    }
}
