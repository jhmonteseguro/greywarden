<?php

namespace app\modules\threat\models;

use app\modules\attack\models\AttackGroup;
use yii\db\ActiveRecord;
use yii\db\Expression;

/**
 * One event in the news: the articles about it, grouped by StoryClusterer. Shared facts;
 * what an organisation makes of it is its ThreatTriage.
 *
 * @property int $id
 * @property string $title
 * @property string|null $summary
 * @property bool|int $is_quiet
 * @property bool|int $is_roundup
 * @property bool|int $exploited_language
 * @property int $article_count
 * @property int $source_count
 * @property string $first_seen_at
 * @property string $last_seen_at
 *
 * @property-read ThreatArticle[] $articles newest first
 * @property-read ThreatCve[] $cves
 */
class ThreatStory extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'threat_story';
    }

    public function getArticles()
    {
        return $this->hasMany(ThreatArticle::class, ['story_id' => 'id'])->orderBy(['published_at' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function getCves()
    {
        return $this->hasMany(ThreatCve::class, ['cve_id' => 'cve_id'])->viaTable('threat_story_cve', ['story_id' => 'id']);
    }

    /** Recounts its coverage after articles were removed; a story with none left goes */
    public function refreshCounts(): void
    {
        $articles = ThreatArticle::find()->where(['story_id' => $this->id]);
        $this->article_count = (int) (clone $articles)->count();
        if ($this->article_count === 0) {
            $this->delete();
            return;
        }
        $this->source_count = (int) $articles->select(new Expression('COUNT(DISTINCT source_id)'))->scalar();
        $this->save(false);
    }

    /** @return AttackGroup[] the threat groups its articles name, by name */
    public function getActors(): array
    {
        return AttackGroup::find()->alias('g')
            ->innerJoin(['x' => 'threat_article_actor'], 'x.group_id = g.id')
            ->innerJoin(['a' => 'threat_article'], 'a.id = x.article_id')
            ->where(['a.story_id' => $this->id])->distinct()->orderBy(['g.name' => SORT_ASC])->all();
    }

    /** CVEs in KEV first, then highest CVSS */
    public function getSortedCves(): array
    {
        $cves = $this->cves;
        usort($cves, static fn(ThreatCve $a, ThreatCve $b) => [(int) $b->getInKev(), (float) $b->cvss_score, $b->cve_id] <=> [(int) $a->getInKev(), (float) $a->cvss_score, $a->cve_id]);
        return $cves;
    }
}
