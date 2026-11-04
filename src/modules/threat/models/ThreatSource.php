<?php

namespace app\modules\threat\models;

use app\models\DomainRecord;

/**
 * A feed Threat Watch reads. Shared by every organisation: a built-in one from
 * ThreatCatalogue, or a custom one an admin added.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $category
 * @property string $url
 * @property string $format rss | kev
 * @property bool|int $is_custom
 * @property bool|int $is_quiet
 * @property bool|int $is_enabled
 * @property int $interval_minutes
 * @property string|null $last_fetched_at
 * @property string|null $last_success_at
 * @property string|null $last_error
 * @property int $failures
 * @property int $items_total
 * @property string|null $body_hash
 */
class ThreatSource extends DomainRecord
{
    public const CATEGORY_EXPLOITED = 'exploited';
    public const CATEGORY_ADVISORY = 'advisory';
    public const CATEGORY_GOVERNMENT = 'government';
    public const CATEGORY_RESEARCH = 'research';
    public const CATEGORY_EXPLOIT = 'exploit';
    public const CATEGORY_NEWS = 'news';

    public const FORMAT_RSS = 'rss';
    public const FORMAT_KEV = 'kev';

    public const CUSTOM_INTERVAL = 60;

    /** What a fetch updates; kept out of the audit trail */
    public const STAT_ATTRIBUTES = ['last_fetched_at', 'last_success_at', 'last_error', 'failures', 'items_total', 'body_hash'];

    public static function tableName(): string
    {
        return 'threat_source';
    }

    public static function entityType(): string
    {
        return 'threat-source';
    }

    public static function entityLabel(): string
    {
        return 'News source';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function viewRoute(): string
    {
        return '/threat/source/index';
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['audit']['ignored'] = array_merge(['created_at', 'updated_at', 'created_by', 'updated_by'], self::STAT_ATTRIBUTES);
        return $behaviors;
    }

    public function getIsArchived(): bool
    {
        return false;
    }

    public function getAuditLabel(): string
    {
        return $this->name;
    }

    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_EXPLOITED => 'Actively exploited',
            self::CATEGORY_ADVISORY => 'Vendor advisories',
            self::CATEGORY_GOVERNMENT => 'Government alerts',
            self::CATEGORY_RESEARCH => 'Threat research',
            self::CATEGORY_EXPLOIT => 'Exploit research',
            self::CATEGORY_NEWS => 'News',
        ];
    }

    public function enumAttributes(): array
    {
        return ['category' => self::categoryOptions()];
    }

    public function booleanAttributes(): array
    {
        return ['is_custom', 'is_quiet', 'is_enabled'];
    }

    public function rules(): array
    {
        return [
            [['name', 'category', 'url'], 'required'],
            [['name', 'url'], 'trim'],
            ['name', 'string', 'max' => 120],
            ['url', 'string', 'max' => 1000],
            ['url', 'unique', 'message' => 'This feed is already on the list.'],
            ['category', 'in', 'range' => array_keys(self::categoryOptions())],
        ];
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'name' => 'Name',
            'category' => 'Type',
            'url' => 'Feed address',
            'is_enabled' => 'Enabled',
            'is_quiet' => 'Background',
            'last_success_at' => 'Last read',
        ];
    }

    public function getCategoryLabel(): string
    {
        return self::categoryOptions()[$this->category] ?? $this->category;
    }

    /** ok | failing | waiting | off */
    public function getHealth(): string
    {
        return match (true) {
            !$this->is_enabled => 'off',
            $this->failures > 0 => 'failing',
            $this->last_success_at === null => 'waiting',
            default => 'ok',
        };
    }

    public function isDue(int $now): bool
    {
        return $this->last_fetched_at === null || $now - strtotime($this->last_fetched_at) >= $this->interval_minutes * 60 - 30;
    }

    public function recordSuccess(string $now, string $hash, int $newItems): void
    {
        self::updateAll([
            'last_fetched_at' => $now, 'last_success_at' => $now, 'last_error' => null, 'failures' => 0, 'body_hash' => $hash,
            'items_total' => (int) $this->items_total + $newItems,
        ], ['id' => $this->id]);
    }

    public function recordFailure(string $now, string $error): void
    {
        self::updateAll(['last_fetched_at' => $now, 'last_error' => mb_substr($error, 0, 500), 'failures' => (int) $this->failures + 1], ['id' => $this->id]);
    }
}
