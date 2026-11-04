<?php

namespace app\modules\threat\models;

use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Expression;
use yii\db\Query;

/**
 * Threat Watch grid: the queue to triage by default, most urgent first.
 */
class ThreatSearch extends Model
{
    public const FILTERS = [
        'new' => 'To triage',
        'urgent' => 'Critical and high',
        'exploited' => 'Exploited',
        'matched' => 'On your watchlist',
        'relevant' => 'Relevant',
        'dismissed' => 'Dismissed',
        'all' => 'All',
        'background' => 'Background advisories',
    ];

    public $filter = 'new';
    public $q;
    public $priority;

    public function rules(): array
    {
        return [
            ['q', 'string', 'max' => 100],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
            ['priority', 'in', 'range' => array_keys(ThreatTriage::priorityOptions())],
        ];
    }

    public function attributeLabels(): array
    {
        return (new ThreatTriage())->attributeLabels();
    }

    public function formName(): string
    {
        return '';
    }

    public static function applyFilter(ThreatTriageQuery $query, string $filter): void
    {
        match ($filter) {
            'new' => $query->open(),
            'urgent' => $query->open()->andWhere(['threat_triage.priority' => ['critical', 'high']]),
            'exploited' => $query->visible()->andWhere(['threat_triage.is_exploited' => 1])->andWhere(['<>', 'threat_triage.status', ThreatTriage::STATUS_DISMISSED]),
            'matched' => $query->visible()->andWhere(['threat_triage.is_matched' => 1])->andWhere(['<>', 'threat_triage.status', ThreatTriage::STATUS_DISMISSED]),
            'relevant', 'dismissed' => $query->visible()->andWhere(['threat_triage.status' => $filter]),
            'background' => $query->andWhere(['threat_triage.is_hidden' => 1]),
            default => $query->visible(),
        };
    }

    /** filter => count */
    public static function counts(): array
    {
        $out = [];
        foreach (array_keys(self::FILTERS) as $filter) {
            $query = ThreatTriage::find()->forOrganisation();
            self::applyFilter($query, $filter);
            $out[$filter] = (int) $query->count();
        }
        return $out;
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = ThreatTriage::find()->forOrganisation()->joinWith('story')->with(['story.cves', 'story.articles.source']);
        $priority = static fn(string $dir) => new Expression("FIELD(threat_triage.priority, 'critical','high','medium','low') $dir");
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['priority' => SORT_ASC],
                'attributes' => [
                    'priority' => [
                        'asc' => [$priority('ASC'), 'threat_triage.score' => SORT_DESC, 'threat_story.last_seen_at' => SORT_DESC],
                        'desc' => [$priority('DESC'), 'threat_triage.score' => SORT_ASC],
                    ],
                    'last_seen_at' => ['asc' => ['threat_story.last_seen_at' => SORT_ASC], 'desc' => ['threat_story.last_seen_at' => SORT_DESC]],
                    'article_count' => ['asc' => ['threat_story.source_count' => SORT_ASC], 'desc' => ['threat_story.source_count' => SORT_DESC]],
                ],
            ],
        ]);

        $this->load($params);
        if (!$this->validate()) {
            $query->where('0=1');
            return $dataProvider;
        }
        self::applyFilter($query, $this->filter ?: 'all');
        if ($this->q !== null && trim($this->q) !== '') {
            $q = trim($this->q);
            $query->andWhere(['or',
                ['like', 'threat_story.title', $q],
                ['like', 'threat_story.summary', $q],
                ['threat_story.id' => (new Query())->select('story_id')->from('threat_story_cve')->where(['like', 'cve_id', $q])],
            ]);
        }
        $query->andFilterWhere(['threat_triage.priority' => $this->priority]);
        return $dataProvider;
    }
}
