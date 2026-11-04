<?php

namespace app\modules\threat\models;

use app\models\DomainRecord;
use app\models\Organisation;

/**
 * A word or product an organisation cares about. Stories mentioning it rank higher.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $term
 * @property string $category
 * @property string $origin starter | manual | device | cloud | supplier
 * @property bool|int $is_enabled
 */
class ThreatWatchTerm extends DomainRecord
{
    public const ORIGIN_STARTER = 'starter';
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_DEVICE = 'device';
    public const ORIGIN_CLOUD = 'cloud';
    public const ORIGIN_SUPPLIER = 'supplier';

    /** Origins kept up to date from the asset registers by Watchlist::sync() */
    public const DERIVED_ORIGINS = [self::ORIGIN_DEVICE, self::ORIGIN_CLOUD, self::ORIGIN_SUPPLIER];

    public static function tableName(): string
    {
        return 'threat_watch_term';
    }

    public static function entityType(): string
    {
        return 'threat-term';
    }

    public static function entityLabel(): string
    {
        return 'Watchlist term';
    }

    public static function titleAttribute(): string
    {
        return 'term';
    }

    public static function viewRoute(): string
    {
        return '/threat/watchlist/index';
    }

    public function getIsArchived(): bool
    {
        return false;
    }

    public function getAuditLabel(): string
    {
        return $this->term;
    }

    public static function categoryOptions(): array
    {
        return [
            'network' => 'Firewalls and VPNs',
            'identity' => 'Identity',
            'email' => 'Email',
            'endpoint' => 'Endpoint and EDR',
            'platform' => 'Platforms',
            'device' => 'From your devices',
            'supplier' => 'From your suppliers',
            'other' => 'Other',
        ];
    }

    public static function originOptions(): array
    {
        return [
            self::ORIGIN_STARTER => 'Starter list',
            self::ORIGIN_MANUAL => 'Added by hand',
            self::ORIGIN_DEVICE => 'Device Inventory',
            self::ORIGIN_CLOUD => 'Cloud Accounts',
            self::ORIGIN_SUPPLIER => 'Supplier Register',
        ];
    }

    public function enumAttributes(): array
    {
        return ['category' => self::categoryOptions(), 'origin' => self::originOptions()];
    }

    public function booleanAttributes(): array
    {
        return ['is_enabled'];
    }

    public function rules(): array
    {
        return [
            ['term', 'trim'],
            [['term', 'category', 'origin', 'organisation_id'], 'required'],
            ['term', 'string', 'min' => 2, 'max' => 120],
            ['term', 'unique', 'targetAttribute' => ['organisation_id', 'term'], 'message' => 'This term is already on the watchlist.'],
            ['category', 'in', 'range' => array_keys(self::categoryOptions())],
            ['origin', 'in', 'range' => array_keys(self::originOptions())],
        ];
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + ['term' => 'Term', 'category' => 'Group', 'origin' => 'From', 'is_enabled' => 'On'];
    }

    public function getIsDerived(): bool
    {
        return in_array($this->origin, self::DERIVED_ORIGINS, true);
    }

    /** The current organisation's term, or null */
    public static function findOwn(int $id): ?self
    {
        return self::findOne(['id' => $id, 'organisation_id' => Organisation::currentId()]);
    }
}
