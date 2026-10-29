<?php

namespace app\modules\intel\models;

use app\components\Actor;
use app\models\DomainRecord;
use app\modules\incident\models\Incident;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\IpAddress;
use RuntimeException;

/**
 * Blocklist entry: a single IP address, a network (CIDR) or an autonomous system.
 * Archiving an entry = unblocking it (kept for the record and restorable);
 * entries with an expiry date stop applying after it.
 *
 * @property int $id
 * @property string $reference BLK-0001
 * @property string $indicator_type ip | cidr | asn
 * @property string $value normalised: 192.0.2.1, 198.51.100.0/24, AS64496
 * @property string|null $range_start hex (see IpAddress::toHex)
 * @property string|null $range_end
 * @property int|null $asn
 * @property string $severity
 * @property string $direction both | inbound | outbound
 * @property string $reason
 * @property int|null $incident_id
 * @property string|null $expires_at
 * @property string|null $cloudflare_added_at when it was added to the Cloudflare edge rule
 *
 * @property-read Incident|null $incident
 */
class BlocklistEntry extends DomainRecord
{
    public const TYPE_IP = 'ip';
    public const TYPE_CIDR = 'cidr';
    public const TYPE_ASN = 'asn';

    /** Broadest networks that may be blocked */
    public const MIN_PREFIX_V4 = 8;
    public const MIN_PREFIX_V6 = 16;

    /** Form: keep the entry in the Cloudflare edge rule (see syncCloudflare) */
    public bool $cloudflare = false;
    /** Set by syncCloudflare for the flash message */
    public ?string $cloudflareNotice = null;

    public static function tableName(): string
    {
        return 'intel_blocklist';
    }

    public static function entityType(): string
    {
        return 'blocklist';
    }

    public static function entityLabel(): string
    {
        return 'Blocklist entry';
    }

    public static function referencePrefix(): ?string
    {
        return 'BLK';
    }

    public static function titleAttribute(): string
    {
        return 'value';
    }

    public static function viewRoute(): string
    {
        return '/intel/blocklist/view';
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['audit']['ignored'] = ['created_at', 'updated_at', 'created_by', 'updated_by', 'range_start', 'range_end'];
        return $behaviors;
    }

    public static function typeOptions(): array
    {
        return [self::TYPE_IP => 'IP address', self::TYPE_CIDR => 'Network (CIDR)', self::TYPE_ASN => 'Autonomous system'];
    }

    public static function severityOptions(): array
    {
        return Incident::severityOptions();
    }

    public static function directionOptions(): array
    {
        return ['both' => 'Inbound & outbound', 'inbound' => 'Inbound only', 'outbound' => 'Outbound only'];
    }

    public function enumAttributes(): array
    {
        return [
            'indicator_type' => self::typeOptions(),
            'severity' => self::severityOptions(),
            'direction' => self::directionOptions(),
        ];
    }

    public function userAttributes(): array
    {
        return ['archived_by'];
    }

    public function dateTimeAttributes(): array
    {
        return ['expires_at', 'cloudflare_added_at'];
    }

    public function afterFind(): void
    {
        parent::afterFind();
        $this->cloudflare = $this->cloudflare_added_at !== null;
    }

    public function formatAuditValue(string $attribute, mixed $value): string
    {
        if ($attribute === 'incident_id') {
            return Incident::find()->select('reference')->where(['id' => $value])->scalar() ?: '#' . $value;
        }
        return parent::formatAuditValue($attribute, $value);
    }

    public function rules(): array
    {
        return [
            [['value', 'reason'], 'trim'],
            [['value', 'reason', 'severity', 'direction'], 'required'],
            ['value', 'string', 'max' => 64],
            ['value', 'normalizeValue'],
            ['reason', 'string', 'max' => 2000],
            ['severity', 'in', 'range' => array_keys(self::severityOptions())],
            ['direction', 'in', 'range' => array_keys(self::directionOptions())],
            ['incident_id', 'integer'],
            ['incident_id', 'exist', 'targetClass' => Incident::class, 'targetAttribute' => 'id', 'skipOnEmpty' => true],
            ['expires_at', 'datetime', 'format' => 'php:Y-m-d H:i:s', 'skipOnEmpty' => true],
            ['expires_at', 'validateExpiry'],
            ['cloudflare', 'filter', 'filter' => 'boolval'],
            ['cloudflare', 'validateCloudflare'],
        ];
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'reference' => 'Reference',
            'indicator_type' => 'Type',
            'value' => 'IP, network or ASN',
            'severity' => 'Severity',
            'direction' => 'Direction',
            'reason' => 'Reason',
            'incident_id' => 'Related incident',
            'expires_at' => 'Expires',
            'cloudflare' => 'Add to Cloudflare',
            'cloudflare_added_at' => 'Added to Cloudflare',
            'archived_at' => 'Unblocked',
            'archived_by' => 'Unblocked by',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'value' => 'An address (203.0.113.7), a network in CIDR notation (198.51.100.0/24) or an ASN (AS64496).',
            'expires_at' => 'Optional. Leave empty for a permanent block.',
            'direction' => 'Which traffic the block should apply to on your firewalls.',
            'cloudflare' => Cloudflare::isConfigured()
                ? sprintf('Adds it to the “%s” WAF rule as an OR condition. It stays there until it is unblocked here or expires (expired entries are taken out by `make expire-blocks`, which every edge pull also runs).', Cloudflare::ruleName())
                : 'Set CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID in .env to enable.',
        ];
    }

    /** Detect the type and normalise the value; fill range / asn columns. */
    public function normalizeValue(string $attribute): void
    {
        $raw = trim((string) $this->value);
        if (str_contains($raw, '/')) {
            $range = IpAddress::parseCidr($raw);
            if (!$range) {
                $this->addError($attribute, 'Enter a valid network, e.g. 198.51.100.0/24.');
                return;
            }
            $min = $range['version'] === 4 ? self::MIN_PREFIX_V4 : self::MIN_PREFIX_V6;
            if ($range['prefix'] < $min) {
                $this->addError($attribute, sprintf('Networks broader than /%d cannot be blocked.', $min));
                return;
            }
            $single = $range['prefix'] === ($range['version'] === 4 ? 32 : 128);
            $this->indicator_type = $single ? self::TYPE_IP : self::TYPE_CIDR;
            $this->value = $single ? $range['network'] : $range['cidr'];
            $this->range_start = $range['start'];
            $this->range_end = $range['end'];
            $this->asn = null;
        } elseif ($indicator = Indicator::parse($raw)) {
            if ($indicator->isIp()) {
                $this->indicator_type = self::TYPE_IP;
                $this->range_start = $this->range_end = IpAddress::toHex($indicator->value);
                $this->asn = null;
            } else {
                $this->indicator_type = self::TYPE_ASN;
                $this->range_start = $this->range_end = null;
                $this->asn = $indicator->asn;
            }
            $this->value = $indicator->value;
        } else {
            $this->addError($attribute, 'Enter an IP address, a CIDR network or an ASN (e.g. AS64496).');
            return;
        }
        $query = self::find()->active()->andWhere(['value' => $this->value]);
        if ($this->id) {
            $query->andWhere(['<>', 'id', $this->id]);
        }
        $duplicate = $query->one();
        if ($duplicate) {
            $this->addError($attribute, sprintf('%s is already blocked (%s).', $this->value, $duplicate->reference));
        }
    }

    public function validateExpiry(string $attribute): void
    {
        if ($this->expires_at && !$this->hasErrors($attribute) && $this->isAttributeChanged($attribute) && strtotime($this->expires_at) <= strtotime(Actor::now())) {
            $this->addError($attribute, 'The expiry must be in the future.');
        }
    }

    public function validateCloudflare(string $attribute): void
    {
        if ($this->cloudflare && $this->direction === 'outbound') {
            $this->addError($attribute, 'Cloudflare only filters inbound requests to your sites; choose inbound or both.');
        }
    }

    /** The condition this entry adds to the Cloudflare rule */
    public function getCloudflareClause(): string
    {
        return Cloudflare::clause($this->indicator_type, $this->value);
    }

    /**
     * Bring the Cloudflare rule in line with $cloudflare (and a changed value) before saving:
     * adds, removes or replaces this entry's condition and sets cloudflare_added_at.
     * Call after validate(); on failure the error is added to `cloudflare`.
     */
    public function syncCloudflare(): bool
    {
        $wasAdded = $this->getOldAttribute('cloudflare_added_at') !== null;
        $old = $wasAdded ? Cloudflare::clause($this->getOldAttribute('indicator_type'), $this->getOldAttribute('value')) : null;
        $new = $this->cloudflare && !$this->getIsArchived() ? $this->getCloudflareClause() : null;
        if ($old === $new) {
            return true;
        }
        try {
            $result = Cloudflare::replace($old, $new);
        } catch (RuntimeException $e) {
            $this->addError('cloudflare', $e->getMessage());
            return false;
        }
        $this->cloudflare_added_at = $new !== null ? Actor::now() : null;
        $rule = Cloudflare::ruleName();
        $this->cloudflareNotice = match (true) {
            $new === null => sprintf('Removed from the Cloudflare rule “%s”.', $rule),
            $old === null => sprintf('Added to the Cloudflare rule “%s”.', $rule),
            default => sprintf('Updated in the Cloudflare rule “%s”.', $rule),
        } . ($new !== null && !$result['enabled'] ? ' The rule is disabled in Cloudflare, so it is not blocking yet.' : '');
        return true;
    }

    /**
     * Takes expired entries out of the Cloudflare rule; they stay on the blocklist
     * as Expired, so editing the expiry and ticking "Add to Cloudflare" again
     * puts them back.
     *
     * @return array{removed: string[], failed: array<string, string>} references, and reference => error
     */
    public static function removeExpiredFromCloudflare(): array
    {
        $out = ['removed' => [], 'failed' => []];
        $expired = self::find()->active()->andWhere(['not', ['cloudflare_added_at' => null]])
            ->andWhere(['<=', 'expires_at', Actor::now()])->orderBy(['id' => SORT_ASC])->all();
        if ($expired && !Cloudflare::isConfigured()) {
            foreach ($expired as $entry) {
                $out['failed'][$entry->reference] = 'Cloudflare is not configured.';
            }
            return $out;
        }
        foreach ($expired as $entry) {
            try {
                Cloudflare::replace($entry->getCloudflareClause(), null);
            } catch (RuntimeException $e) {
                $out['failed'][$entry->reference] = $e->getMessage();
                continue;
            }
            $entry->cloudflare_added_at = null;
            $entry->cloudflare = false;
            $entry->save(false);
            $out['removed'][] = $entry->reference;
        }
        return $out;
    }

    public function getIncident()
    {
        return $this->hasOne(Incident::class, ['id' => 'incident_id']);
    }

    public function getIsExpired(): bool
    {
        return $this->expires_at !== null && strtotime($this->expires_at) <= strtotime(Actor::now());
    }

    /** In force: not unblocked and not expired */
    public function getIsInForce(): bool
    {
        return !$this->getIsArchived() && !$this->getIsExpired();
    }

    /** blocked | expired | unblocked */
    public function getState(): string
    {
        return $this->getIsArchived() ? 'unblocked' : ($this->getIsExpired() ? 'expired' : 'blocked');
    }

    public function getTypeLabel(): string
    {
        return self::typeOptions()[$this->indicator_type] ?? $this->indicator_type;
    }

    /** The investigation page for the entry (network address for a CIDR) */
    public function getIndicatorUrl(): ?array
    {
        $indicator = $this->indicator_type === self::TYPE_CIDR
            ? Indicator::ip(explode('/', $this->value)[0])
            : Indicator::parse($this->value);
        return $indicator?->url();
    }

    /**
     * Entries (not unblocked) that cover an IP: exact, a containing network, or its origin AS.
     * @return self[]
     */
    public static function findForIp(string $ip, ?int $asn = null): array
    {
        $hex = IpAddress::toHex($ip);
        $where = ['or', ['and', ['indicator_type' => [self::TYPE_IP, self::TYPE_CIDR]], ['<=', 'range_start', $hex], ['>=', 'range_end', $hex]]];
        if ($asn) {
            $where[] = ['and', ['indicator_type' => self::TYPE_ASN], ['asn' => $asn]];
        }
        return self::find()->active()->andWhere($where)->with(['incident', 'creator'])->orderBy(['indicator_type' => SORT_DESC, 'id' => SORT_DESC])->all();
    }

    /** @return self[] entries for an AS */
    public static function findForAsn(int $asn): array
    {
        return self::find()->active()->andWhere(['indicator_type' => self::TYPE_ASN, 'asn' => $asn])->with(['incident', 'creator'])->all();
    }
}
