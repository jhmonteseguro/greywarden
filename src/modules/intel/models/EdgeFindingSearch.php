<?php

namespace app\modules\intel\models;

use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Expression;

/**
 * Edge findings grid: the triage queue (new) by default, highest priority first.
 */
class EdgeFindingSearch extends Model
{
    public const FILTERS = [
        'new' => 'To triage',
        'high' => 'High priority',
        'exposure' => 'Possible exposure',
        'blocked' => 'Blocked',
        'escalated' => 'Incident raised',
        'dismissed' => 'Dismissed',
        'ignored' => 'Ignored',
        'all' => 'All',
    ];

    public $filter = 'new';
    public $q;
    public $priority;
    public $intel_rating;

    public function rules(): array
    {
        return [
            ['q', 'string', 'max' => 100],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
            ['priority', 'in', 'range' => array_keys(EdgeFinding::priorityOptions())],
            ['intel_rating', 'string', 'max' => 16],
        ];
    }

    public function attributeLabels(): array
    {
        return (new EdgeFinding())->attributeLabels();
    }

    public function formName(): string
    {
        return '';
    }

    public static function applyFilter($query, string $filter): void
    {
        match ($filter) {
            'new' => $query->andWhere(['edge_finding.status' => EdgeFinding::STATUS_NEW]),
            'high' => $query->andWhere(['edge_finding.status' => EdgeFinding::STATUS_NEW, 'edge_finding.priority' => 'high']),
            'exposure' => $query->andWhere(['edge_finding.exposure' => 1])->andWhere(['<>', 'edge_finding.status', EdgeFinding::STATUS_IGNORED]),
            'blocked', 'escalated', 'dismissed', 'ignored' => $query->andWhere(['edge_finding.status' => $filter]),
            default => null,
        };
    }

    /** filter => count */
    public static function counts(): array
    {
        $out = [];
        foreach (array_keys(self::FILTERS) as $filter) {
            $q = EdgeFinding::find();
            self::applyFilter($q, $filter);
            $out[$filter] = (int) $q->count();
        }
        return $out;
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = EdgeFinding::find()->with(['blocklistEntry', 'incident']);
        $priority = static fn(string $dir) => new Expression("FIELD(edge_finding.priority, 'high','medium','low') $dir");
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['priority' => SORT_ASC],
                'attributes' => [
                    'reference' => ['asc' => ['edge_finding.id' => SORT_ASC], 'desc' => ['edge_finding.id' => SORT_DESC]],
                    'priority' => [
                        'asc' => [$priority('ASC'), 'edge_finding.score' => SORT_DESC, 'edge_finding.last_seen_at' => SORT_DESC],
                        'desc' => [$priority('DESC'), 'edge_finding.score' => SORT_ASC],
                    ],
                    'score', 'requests', 'last_seen_at', 'first_seen_at', 'intel_score',
                ],
            ],
        ]);

        $this->load($params);
        if (!$this->validate()) {
            $query->where('0=1');
            return $dataProvider;
        }
        self::applyFilter($query, $this->filter ?: 'all');
        if ($this->q !== null && $this->q !== '') {
            $asn = preg_match('/^AS?(\d+)$/i', trim($this->q), $m) ? (int) $m[1] : null;
            $query->andWhere(['or',
                ['like', 'edge_finding.reference', $this->q],
                ['like', 'edge_finding.ip', $this->q],
                ['like', 'edge_finding.headline', $this->q],
                ['like', 'edge_finding.evidence', $this->q],
                $asn !== null ? ['edge_finding.asn' => $asn] : '0=1',
            ]);
        }
        $query->andFilterWhere(['edge_finding.priority' => $this->priority, 'edge_finding.intel_rating' => $this->intel_rating]);
        return $dataProvider;
    }
}
