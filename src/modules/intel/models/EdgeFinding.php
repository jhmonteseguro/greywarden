<?php

namespace app\modules\intel\models;

use app\components\Actor;
use app\models\DomainRecord;
use app\models\User;
use app\modules\incident\models\Incident;
use app\modules\intel\components\edge\EdgeAnalyzer;
use app\modules\intel\components\Indicator;

/**
 * Edge finding: a client address whose traffic through Cloudflare looked like
 * probing or scanning. One per address; a later pull adds to it, and reopens a
 * dismissed or blocked one when the address comes back (see EdgePuller).
 *
 * @property int $id
 * @property string $reference EDG-0001
 * @property string $ip
 * @property int|null $asn
 * @property string|null $country
 * @property string $status new | blocked | escalated | dismissed | ignored
 * @property int $score
 * @property string $priority high | medium | low
 * @property string $headline
 * @property bool|int $exposure
 * @property string|null $signals JSON
 * @property string|null $evidence JSON profile (EdgeAnalyzer)
 * @property int $requests
 * @property int $stopped
 * @property int $error_paths
 * @property int $times_seen
 * @property string $first_seen_at
 * @property string $last_seen_at
 * @property int|null $intel_score
 * @property string|null $intel_rating
 * @property string|null $intel_checked_at
 * @property int|null $blocklist_id
 * @property int|null $incident_id
 * @property string|null $note
 * @property string|null $resolved_at
 * @property int|null $resolved_by
 *
 * @property-read BlocklistEntry|null $blocklistEntry
 * @property-read Incident|null $incident
 * @property-read User|null $resolver
 */
class EdgeFinding extends DomainRecord
{
    public const STATUS_NEW = 'new';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_IGNORED = 'ignored';

    /** Statuses a pull reopens when the address comes back with a high enough score */
    public const REOPENABLE = [self::STATUS_DISMISSED, self::STATUS_BLOCKED];

    /** Traffic statistics a pull refreshes; kept out of the audit trail */
    public const STAT_ATTRIBUTES = ['score', 'priority', 'headline', 'signals', 'evidence', 'requests', 'stopped', 'error_paths', 'times_seen', 'last_seen_at', 'asn', 'country',
        'intel_score', 'intel_rating', 'intel_checked_at'];

    public static function tableName(): string
    {
        return 'edge_finding';
    }

    public static function entityType(): string
    {
        return 'edge-finding';
    }

    public static function entityLabel(): string
    {
        return 'Edge finding';
    }

    public static function referencePrefix(): ?string
    {
        return 'EDG';
    }

    public static function titleAttribute(): string
    {
        return 'ip';
    }

    public static function viewRoute(): string
    {
        return '/intel/edge/view';
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['audit']['ignored'] = array_merge(['created_at', 'updated_at', 'created_by', 'updated_by', 'first_seen_at'], self::STAT_ATTRIBUTES);
        return $behaviors;
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'New',
            self::STATUS_BLOCKED => 'Blocked',
            self::STATUS_ESCALATED => 'Incident raised',
            self::STATUS_DISMISSED => 'Dismissed',
            self::STATUS_IGNORED => 'Ignored',
        ];
    }

    public static function priorityOptions(): array
    {
        return ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
    }

    public function enumAttributes(): array
    {
        return ['status' => self::statusOptions(), 'priority' => self::priorityOptions()];
    }

    public function userAttributes(): array
    {
        return ['archived_by', 'resolved_by'];
    }

    public function booleanAttributes(): array
    {
        return ['exposure'];
    }

    public function formatAuditValue(string $attribute, mixed $value): string
    {
        if ($attribute === 'incident_id') {
            return Incident::find()->select('reference')->where(['id' => $value])->scalar() ?: '#' . $value;
        }
        if ($attribute === 'blocklist_id') {
            return BlocklistEntry::find()->select('reference')->where(['id' => $value])->scalar() ?: '#' . $value;
        }
        return parent::formatAuditValue($attribute, $value);
    }

    public function rules(): array
    {
        return [
            [['ip', 'status', 'first_seen_at', 'last_seen_at'], 'required'],
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            ['priority', 'in', 'range' => array_keys(self::priorityOptions())],
            ['note', 'trim'],
            ['note', 'string', 'max' => 500],
        ];
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'reference' => 'Reference',
            'ip' => 'Address',
            'asn' => 'ASN',
            'country' => 'Country',
            'status' => 'Status',
            'score' => 'Edge score',
            'priority' => 'Priority',
            'headline' => 'Why',
            'exposure' => 'Possible exposure',
            'requests' => 'Requests',
            'stopped' => 'Stopped by Cloudflare',
            'error_paths' => 'Paths with errors',
            'times_seen' => 'Pulls seen in',
            'first_seen_at' => 'First seen',
            'last_seen_at' => 'Last seen',
            'intel_rating' => 'Intel rating',
            'intel_score' => 'Intel score',
            'blocklist_id' => 'Blocklist entry',
            'incident_id' => 'Incident',
            'note' => 'Note',
            'resolved_at' => 'Resolved',
            'resolved_by' => 'Resolved by',
        ];
    }

    public function getBlocklistEntry()
    {
        return $this->hasOne(BlocklistEntry::class, ['id' => 'blocklist_id']);
    }

    public function getIncident()
    {
        return $this->hasOne(Incident::class, ['id' => 'incident_id']);
    }

    public function getResolver()
    {
        return $this->hasOne(User::class, ['id' => 'resolved_by']);
    }

    public function getIsOpen(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    /** @return array[] signals (EdgeAnalyzer::score), strongest first */
    public function getSignalList(): array
    {
        return json_decode((string) $this->signals, true) ?: [];
    }

    /** The traffic profile (EdgeAnalyzer), with every key present */
    public function getProfile(): array
    {
        return (json_decode((string) $this->evidence, true) ?: []) + EdgeAnalyzer::emptyProfile();
    }

    public function setProfile(array $profile): void
    {
        $this->evidence = json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Requests that got past Cloudflare */
    public function getReached(): int
    {
        return max(0, (int) $this->requests - (int) $this->stopped);
    }

    public function getIndicator(): ?Indicator
    {
        return Indicator::ip($this->ip);
    }

    public function getIndicatorUrl(): ?array
    {
        return $this->getIndicator()?->url();
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? $this->status;
    }

    /** Close the finding with a status (blocked, escalated, dismissed, ignored) */
    public function resolve(string $status, ?string $note = null): bool
    {
        $this->status = $status;
        $this->resolved_at = Actor::now();
        $this->resolved_by = Actor::id();
        if ($note !== null && trim($note) !== '') {
            $this->note = mb_substr(trim($note), 0, 500);
        }
        return $this->save();
    }

    public function reopen(): bool
    {
        $this->status = self::STATUS_NEW;
        $this->resolved_at = null;
        $this->resolved_by = null;
        return $this->save();
    }
}
