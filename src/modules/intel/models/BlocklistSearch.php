<?php

namespace app\modules\intel\models;

use app\components\Actor;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Expression;

/**
 * Blocklist grid: "kind" picks the page (IP addresses & networks, or ASNs),
 * "filter" the state (in force / expired / unblocked / all).
 */
class BlocklistSearch extends Model
{
    public const KINDS = ['ip' => 'IP addresses & networks', 'asn' => 'Autonomous systems'];
    public const FILTERS = ['active' => 'In force', 'expiring' => 'Expiring in 7 days', 'expired' => 'Expired', 'unblocked' => 'Unblocked', 'all' => 'All'];

    public $kind = 'ip';
    public $filter = 'active';
    public $q;
    public $reference;
    public $value;
    public $indicator_type;
    public $severity;
    public $direction;
    public $created_by;

    public function rules(): array
    {
        return [
            [['q', 'reference', 'value'], 'string', 'max' => 100],
            ['kind', 'in', 'range' => array_keys(self::KINDS)],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
            ['indicator_type', 'in', 'range' => array_keys(BlocklistEntry::typeOptions())],
            ['severity', 'in', 'range' => array_keys(BlocklistEntry::severityOptions())],
            ['direction', 'in', 'range' => array_keys(BlocklistEntry::directionOptions())],
            ['created_by', 'integer'],
        ];
    }

    public function attributeLabels(): array
    {
        return (new BlocklistEntry())->attributeLabels() + ['created_by' => 'Blocked by', 'value' => 'Indicator'];
    }

    public function formName(): string
    {
        return '';
    }

    /** Apply a state filter to a query (shared with the counts) */
    public static function applyFilter($query, string $filter): void
    {
        $now = Actor::now();
        switch ($filter) {
            case 'unblocked':
                $query->archived();
                break;
            case 'expired':
                $query->active()->andWhere(['<=', 'intel_blocklist.expires_at', $now]);
                break;
            case 'expiring':
                $query->active()->andWhere(['>', 'intel_blocklist.expires_at', $now])
                    ->andWhere(['<=', 'intel_blocklist.expires_at', date('Y-m-d H:i:s', strtotime($now . ' +7 days'))]);
                break;
            case 'active':
                $query->active()->andWhere(['or', ['intel_blocklist.expires_at' => null], ['>', 'intel_blocklist.expires_at', $now]]);
                break;
        }
    }

    public static function applyKind($query, string $kind): void
    {
        $query->andWhere(['intel_blocklist.indicator_type' => $kind === 'asn' ? [BlocklistEntry::TYPE_ASN] : [BlocklistEntry::TYPE_IP, BlocklistEntry::TYPE_CIDR]]);
    }

    /** kind => filter => count */
    public static function counts(): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $kind) {
            foreach (array_keys(self::FILTERS) as $filter) {
                $q = BlocklistEntry::find();
                self::applyKind($q, $kind);
                self::applyFilter($q, $filter);
                $out[$kind][$filter] = (int) $q->count();
            }
        }
        return $out;
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = BlocklistEntry::find()->with(['incident', 'creator', 'archiver']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['created_at' => SORT_DESC],
                'attributes' => [
                    'reference' => ['asc' => ['intel_blocklist.id' => SORT_ASC], 'desc' => ['intel_blocklist.id' => SORT_DESC]],
                    // Numeric order of addresses / ASNs
                    'value' => ['asc' => ['intel_blocklist.range_start' => SORT_ASC, 'intel_blocklist.asn' => SORT_ASC], 'desc' => ['intel_blocklist.range_start' => SORT_DESC, 'intel_blocklist.asn' => SORT_DESC]],
                    'indicator_type', 'direction', 'created_at', 'expires_at', 'archived_at',
                    'severity' => [
                        'asc' => [new Expression("FIELD(intel_blocklist.severity, 'low','medium','high','critical')")],
                        'desc' => [new Expression("FIELD(intel_blocklist.severity, 'critical','high','medium','low')")],
                    ],
                    'created_by',
                ],
            ],
        ]);

        $this->load($params);
        if (!$this->validate()) {
            $query->where('0=1');
            return $dataProvider;
        }
        self::applyKind($query, $this->kind);
        self::applyFilter($query, $this->filter ?: 'all');

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or',
                ['like', 'intel_blocklist.reference', $this->q],
                ['like', 'intel_blocklist.value', $this->q],
                ['like', 'intel_blocklist.reason', $this->q],
            ]);
        }
        $query->andFilterWhere(['like', 'intel_blocklist.reference', $this->reference])
            ->andFilterWhere(['like', 'intel_blocklist.value', $this->value])
            ->andFilterWhere([
                'intel_blocklist.indicator_type' => $this->indicator_type,
                'intel_blocklist.severity' => $this->severity,
                'intel_blocklist.direction' => $this->direction,
                'intel_blocklist.created_by' => $this->created_by,
            ]);
        return $dataProvider;
    }
}
