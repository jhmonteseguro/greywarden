<?php

namespace app\modules\threat\models;

use app\models\Organisation;
use app\modules\attack\models\AttackGroup;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;
use yii\db\Query;

/**
 * Threat Actors grid: the ATT&CK groups, the ones most recently in this organisation's
 * news first.
 */
class ActorSearch extends Model
{
    public const FILTERS = ['all' => 'All actors', 'named' => 'In the news'];

    public $filter = 'all';
    public $q;

    public function rules(): array
    {
        return [
            ['q', 'string', 'max' => 100],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function formName(): string
    {
        return '';
    }

    /** Stories per group among this organisation's own */
    private static function mentions(): Query
    {
        return (new Query())->select(['x.group_id', 'stories' => 'COUNT(DISTINCT a.story_id)', 'latest' => 'MAX(a.published_at)'])
            ->from(['x' => 'threat_article_actor'])
            ->innerJoin(['a' => 'threat_article'], 'a.id = x.article_id')
            ->innerJoin(['t' => 'threat_triage'], 't.story_id = a.story_id')
            ->where(['t.organisation_id' => Organisation::currentId()])
            ->groupBy('x.group_id');
    }

    private static function query(string $filter): ActiveQuery
    {
        $query = AttackGroup::find()->alias('g')->where(['g.is_deprecated' => 0]);
        $mentions = self::mentions();
        $filter === 'named' ? $query->innerJoin(['m' => $mentions], 'm.group_id = g.id') : $query->leftJoin(['m' => $mentions], 'm.group_id = g.id');
        return $query;
    }

    /** filter => count */
    public static function counts(): array
    {
        return array_combine(array_keys(self::FILTERS), array_map(static fn(string $filter) => (int) self::query($filter)->count(), array_keys(self::FILTERS)));
    }

    public function search(array $params): ActiveDataProvider
    {
        $this->load($params);
        if (!$this->validate()) {
            $this->filter = 'all';
            $this->q = null;
        }
        $techniques = (new Query())->select(['u.group_id', 'n' => 'COUNT(*)'])->from(['u' => 'attack_group_technique'])
            ->innerJoin(['t' => 'attack_technique'], 't.id = u.technique_id')->where(['t.is_deprecated' => 0])->groupBy('u.group_id');
        $query = self::query($this->filter)
            ->select(['g.*', 'story_count' => 'COALESCE(m.stories, 0)', 'last_named_at' => 'm.latest', 'technique_count' => 'COALESCE(k.n, 0)'])
            ->leftJoin(['k' => $techniques], 'k.group_id = g.id');
        if ((string) $this->q !== '') {
            $query->andWhere(['or', ['like', 'g.name', $this->q], ['like', 'g.aliases', $this->q], ['g.external_id' => $this->q]]);
        }
        return new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
            'sort' => [
                'attributes' => [
                    'name' => ['asc' => ['g.name' => SORT_ASC], 'desc' => ['g.name' => SORT_DESC]],
                    'technique_count' => ['asc' => ['technique_count' => SORT_ASC, 'g.name' => SORT_ASC], 'desc' => ['technique_count' => SORT_DESC, 'g.name' => SORT_ASC]],
                    'last_named_at' => ['asc' => ['last_named_at' => SORT_ASC, 'g.name' => SORT_ASC], 'desc' => ['last_named_at' => SORT_DESC, 'g.name' => SORT_ASC]],
                ],
                'defaultOrder' => ['last_named_at' => SORT_DESC],
            ],
        ]);
    }
}
