<?php

namespace app\modules\intel\models;

use app\models\User;
use yii\db\ActiveRecord;

/**
 * One pull of the Cloudflare traffic analytics (EdgePuller): the window it
 * covered, what it found, and any error or warning.
 *
 * @property int $id
 * @property string $started_at
 * @property string|null $finished_at
 * @property string|null $window_start
 * @property string|null $window_end
 * @property string $status ok | partial | error
 * @property string|null $message
 * @property int $requests
 * @property int $ips
 * @property int $findings_new
 * @property int $findings_updated
 * @property int|null $run_by
 *
 * @property-read User|null $runner
 */
class EdgePull extends ActiveRecord
{
    public const STATUS_OK = 'ok';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_ERROR = 'error';

    public static function tableName(): string
    {
        return 'edge_pull';
    }

    public function getRunner()
    {
        return $this->hasOne(User::class, ['id' => 'run_by']);
    }

    public static function latest(): ?self
    {
        return self::find()->orderBy(['id' => SORT_DESC])->one();
    }

    /** The last pull that read traffic (its window end is where the next one starts) */
    public static function lastSuccessful(): ?self
    {
        return self::find()->where(['status' => [self::STATUS_OK, self::STATUS_PARTIAL]])->andWhere(['not', ['window_end' => null]])->orderBy(['id' => SORT_DESC])->one();
    }

    /** @return self[] */
    public static function recent(int $limit = 5): array
    {
        return self::find()->with('runner')->orderBy(['id' => SORT_DESC])->limit($limit)->all();
    }

    public function getIsError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }
}
