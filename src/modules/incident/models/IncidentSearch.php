<?php

namespace app\modules\incident\models;

use app\components\Actor;
use app\modules\attack\models\AttackTechnique;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Query;

class IncidentSearch extends Model
{
    public const FILTERS = [
        'all' => 'All',
        'mine' => 'Assigned to me',
        'open' => 'Open',
        'regulator-overdue' => 'Regulator deadline overdue',
        'regulator-pending' => 'Awaiting regulator notice',
        'archived' => 'Archived',
    ];

    public $q;
    public $filter = 'open';
    public $reference;
    public $title;
    public $category_id;
    public $severity;
    public $status;
    public $assigned_to_id;
    public $personal_data_involved;
    /** CIA property affected: confidentiality|integrity|availability */
    public $impact;
    public $month; // YYYY-MM (from dashboard chart)
    /** ATT&CK id prefix or name, e.g. T1566 */
    public $technique;

    public function rules(): array
    {
        return [
            [['q', 'technique', 'reference', 'title', 'severity', 'status'], 'string', 'max' => 100],
            [['category_id', 'assigned_to_id'], 'integer'],
            ['personal_data_involved', 'boolean'],
            ['impact', 'in', 'range' => array_keys(self::impactOptions())],
            ['month', 'match', 'pattern' => '/^\d{4}-\d{2}$/'],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function attributeLabels(): array
    {
        return (new Incident())->attributeLabels() + ['personal_data_involved' => 'PII', 'technique' => 'ATT&CK', 'impact' => 'CIA'];
    }

    /** CIA filter value => label */
    public static function impactOptions(): array
    {
        $out = [];
        foreach (Incident::IMPACT_ATTRIBUTES as $label) {
            $out[strtolower($label)] = $label;
        }
        return $out;
    }

    public function formName(): string
    {
        return '';
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = Incident::find()->with(['category', 'assignedTo', 'techniques']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['reference' => SORT_DESC],
                'attributes' => [
                    'reference' => ['asc' => ['incident.id' => SORT_ASC], 'desc' => ['incident.id' => SORT_DESC]],
                    'title', 'category_id', 'assigned_to_id', 'personal_data_involved',
                    'severity' => [
                        'asc' => [new \yii\db\Expression("FIELD(incident.severity, 'low','medium','high','critical')")],
                        'desc' => [new \yii\db\Expression("FIELD(incident.severity, 'critical','high','medium','low')")],
                    ],
                    'status' => [
                        'asc' => [new \yii\db\Expression("FIELD(incident.status, 'new','triage','investigating','contained','eradicated','recovered','closed')")],
                        'desc' => [new \yii\db\Expression("FIELD(incident.status, 'closed','recovered','eradicated','contained','investigating','triage','new')")],
                    ],
                    'detected_at', 'closed_at', 'updated_at',
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
                $query->active()->open()->andWhere(['incident.assigned_to_id' => Actor::id()]);
                break;
            case 'open':
                $query->active()->open();
                break;
            case 'regulator-overdue':
                $query->active()->regulatorOverdue();
                break;
            case 'regulator-pending':
                $query->active()->regulatorPending();
                break;
            default:
                $query->active();
        }

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or',
                ['like', 'incident.reference', $this->q],
                ['like', 'incident.title', $this->q],
                ['like', 'incident.description', $this->q],
                ['like', 'incident.management_summary', $this->q],
            ]);
        }
        if ($this->month) {
            $start = $this->month . '-01 00:00:00';
            $end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
            $query->andWhere(['>=', 'incident.detected_at', $start])->andWhere(['<', 'incident.detected_at', $end]);
        }

        if ($this->technique !== null && $this->technique !== '') {
            $query->andWhere(['incident.id' => (new Query())->select('incident_id')->from('incident_attack_technique')
                ->where(['technique_id' => AttackTechnique::idsMatching($this->technique) ?: [0]])]);
        }

        if ($this->impact) {
            $query->andWhere(['incident.impact_' . $this->impact => true]);
        }

        $query->andFilterWhere(['like', 'incident.reference', $this->reference])
            ->andFilterWhere(['like', 'incident.title', $this->title])
            ->andFilterWhere([
                'incident.category_id' => $this->category_id,
                'incident.severity' => $this->severity,
                'incident.status' => $this->status,
                'incident.assigned_to_id' => $this->assigned_to_id,
                'incident.personal_data_involved' => $this->personal_data_involved,
            ]);

        return $dataProvider;
    }
}
