<?php

namespace app\modules\threat\models;

use app\models\User;
use yii\db\ActiveRecord;

/**
 * One run of ThreatPuller: what it read, what was new, and any failure.
 *
 * @property int $id
 * @property string $started_at
 * @property string|null $finished_at
 * @property string $status ok | partial | error
 * @property string|null $message
 * @property int $sources_read
 * @property int $sources_failed
 * @property int $articles_new
 * @property int $stories_new
 * @property int $cves_enriched
 * @property int|null $run_by
 *
 * @property-read User|null $runner
 */
class ThreatPull extends ActiveRecord
{
    public const STATUS_OK = 'ok';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_ERROR = 'error';

    public static function tableName(): string
    {
        return 'threat_pull';
    }

    public function getRunner()
    {
        return $this->hasOne(User::class, ['id' => 'run_by']);
    }

    public function getIsError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /** @return self[] newest first */
    public static function recent(int $limit = 5): array
    {
        return self::find()->orderBy(['id' => SORT_DESC])->limit($limit)->all();
    }
}
