<?php

namespace app\modules\threat\models;

use yii\db\ActiveRecord;

/**
 * One item from a feed: its headline, a short extract and the link to the publisher.
 *
 * @property int $id
 * @property int $source_id
 * @property int $story_id
 * @property string $guid_hash
 * @property string $title
 * @property string $url
 * @property string|null $excerpt
 * @property string $published_at
 * @property string $fetched_at
 *
 * @property-read ThreatSource $source
 * @property-read ThreatStory $story
 */
class ThreatArticle extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'threat_article';
    }

    public function getSource()
    {
        return $this->hasOne(ThreatSource::class, ['id' => 'source_id']);
    }

    public function getStory()
    {
        return $this->hasOne(ThreatStory::class, ['id' => 'story_id']);
    }

    /** The publisher's host, shown next to the link so it is clear where it leads */
    public function getHost(): string
    {
        return preg_replace('/^www\./', '', (string) parse_url($this->url, PHP_URL_HOST));
    }
}
