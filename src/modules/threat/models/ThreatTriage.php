<?php

namespace app\modules\threat\models;

use app\components\Actor;
use app\models\DomainRecord;
use app\models\User;

/**
 * What one organisation makes of a story: how it ranks against its watchlist (StoryScorer)
 * and what its analysts decided. Pages link to this rather than the story, so a story
 * can only be reached through the organisation's own record of it.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $story_id
 * @property string $status new | relevant | dismissed
 * @property string $priority critical | high | medium | low
 * @property int $score
 * @property string|null $signals JSON
 * @property string|null $matched_terms JSON
 * @property bool|int $is_matched
 * @property bool|int $is_exploited
 * @property bool|int $is_hidden
 * @property string|null $note
 * @property string|null $resolved_at
 * @property int|null $resolved_by
 *
 * @property-read ThreatStory $story
 * @property-read User|null $resolver
 */
class ThreatTriage extends DomainRecord
{
    public const STATUS_NEW = 'new';
    public const STATUS_RELEVANT = 'relevant';
    public const STATUS_DISMISSED = 'dismissed';

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    /** What the scorer rewrites; kept out of the audit trail */
    public const STAT_ATTRIBUTES = ['priority', 'score', 'signals', 'matched_terms', 'is_matched', 'is_exploited', 'is_hidden'];

    public static function tableName(): string
    {
        return 'threat_triage';
    }

    public static function find(): ThreatTriageQuery
    {
        return new ThreatTriageQuery(static::class);
    }

    public static function entityType(): string
    {
        return 'threat-story';
    }

    public static function entityLabel(): string
    {
        return 'Threat Watch story';
    }

    public static function viewRoute(): string
    {
        return '/threat/story/view';
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

    public function getReferenceValue(): string
    {
        return sprintf('TW-%04d', $this->id);
    }

    public function getTitleValue(): string
    {
        return (string) ($this->story->title ?? '');
    }

    public static function statusOptions(): array
    {
        return [self::STATUS_NEW => 'To triage', self::STATUS_RELEVANT => 'Relevant', self::STATUS_DISMISSED => 'Dismissed'];
    }

    public static function priorityOptions(): array
    {
        return ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
    }

    public function enumAttributes(): array
    {
        return ['status' => self::statusOptions(), 'priority' => self::priorityOptions()];
    }

    public function userAttributes(): array
    {
        return ['resolved_by'];
    }

    public function rules(): array
    {
        return [
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            ['note', 'trim'],
            ['note', 'string', 'max' => 500],
        ];
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'status' => 'Status',
            'priority' => 'Priority',
            'score' => 'Score',
            'note' => 'Note',
            'resolved_at' => 'Decided',
            'resolved_by' => 'Decided by',
        ];
    }

    public function getStory()
    {
        return $this->hasOne(ThreatStory::class, ['id' => 'story_id']);
    }

    public function getResolver()
    {
        return $this->hasOne(User::class, ['id' => 'resolved_by']);
    }

    public function getIsOpen(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? $this->status;
    }

    /** @return array<array{key: string, label: string, weight: int}> strongest first */
    public function getSignalList(): array
    {
        return json_decode((string) $this->signals, true) ?: [];
    }

    /** @return string[] */
    public function getMatchedTermList(): array
    {
        return json_decode((string) $this->matched_terms, true) ?: [];
    }

    /** Mark the story relevant or dismissed */
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

    public function reopen(?string $note = null): bool
    {
        $this->status = self::STATUS_NEW;
        $this->resolved_at = null;
        $this->resolved_by = null;
        if ($note !== null) {
            $this->note = mb_substr($note, 0, 500);
        }
        return $this->save();
    }

    public static function rank(string $priority): int
    {
        return (int) array_search($priority, self::PRIORITIES, true);
    }
}
