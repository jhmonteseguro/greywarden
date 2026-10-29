<?php

namespace app\modules\incident\models;

use app\components\Actor;
use app\models\Attachment;
use app\models\User;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * Timestamped note/action on an incident. Entries are append-only. An entry may
 * link one of the incident's files; the file's name is kept on the entry so it
 * still reads correctly after the file is removed.
 *
 * @property int $id
 * @property int $incident_id
 * @property string $entry_at
 * @property string $entry_type
 * @property string $body
 * @property int|null $attachment_id
 * @property string|null $attachment_name
 * @property string $created_at
 * @property string $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property-read Incident $incident
 * @property-read User|null $creator
 * @property-read Attachment|null $attachment
 */
class IncidentTimeline extends ActiveRecord
{
    public const TYPE_NOTE = 'note';
    public const TYPE_ACTION = 'action';
    public const TYPE_EVIDENCE = 'evidence';
    public const TYPE_COMMUNICATION = 'communication';
    public const TYPE_STATUS = 'status';

    public static function tableName(): string
    {
        return 'incident_timeline';
    }

    public static function typeOptions(): array
    {
        return [
            self::TYPE_NOTE => 'Note',
            self::TYPE_ACTION => 'Action taken',
            self::TYPE_EVIDENCE => 'Evidence',
            self::TYPE_COMMUNICATION => 'Communication',
            self::TYPE_STATUS => 'Status change',
        ];
    }

    /** Types a user can pick (status entries are system generated) */
    public static function userTypeOptions(): array
    {
        $options = self::typeOptions();
        unset($options[self::TYPE_STATUS]);
        return $options;
    }

    public function behaviors(): array
    {
        return [
            'timestamp' => ['class' => TimestampBehavior::class, 'value' => static fn() => Actor::now()],
            'blameable' => ['class' => BlameableBehavior::class, 'value' => static fn() => Actor::id()],
        ];
    }

    public function rules(): array
    {
        return [
            ['body', 'trim'],
            [['incident_id', 'entry_at', 'entry_type', 'body'], 'required'],
            ['body', 'string', 'max' => 10000],
            ['entry_type', 'in', 'range' => array_keys(self::typeOptions())],
            ['entry_at', 'filter', 'filter' => static function ($v) {
                $v = str_replace('T', ' ', trim((string) $v));
                return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v) ? $v . ':00' : $v;
            }],
            ['entry_at', 'datetime', 'format' => 'php:Y-m-d H:i:s'],
            ['incident_id', 'exist', 'targetClass' => Incident::class, 'targetAttribute' => 'id'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'entry_at' => 'When',
            'entry_type' => 'Type',
            'body' => 'Entry',
        ];
    }

    public function getIncident()
    {
        return $this->hasOne(Incident::class, ['id' => 'incident_id']);
    }

    public function getCreator()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getAttachment()
    {
        return $this->hasOne(Attachment::class, ['id' => 'attachment_id']);
    }

    /** Links one of the incident's files to the entry, keeping its name */
    public function linkFile(?Attachment $file): void
    {
        $this->attachment_id = $file?->id;
        $this->attachment_name = $file?->original_name;
    }

    /** The linked file's name, whether or not the file is still attached */
    public function getFileName(): ?string
    {
        return $this->attachment->original_name ?? $this->attachment_name;
    }

    public function getIsFileRemoved(): bool
    {
        return $this->attachment_name !== null && $this->attachment === null;
    }

    /** Session key holding a refused entry, so the form can offer it again */
    public static function draftKey(int $incidentId): string
    {
        return 'timeline-draft.' . $incidentId;
    }

    public function getTypeLabel(): string
    {
        return self::typeOptions()[$this->entry_type] ?? $this->entry_type;
    }

    public static function add(int $incidentId, string $type, string $body, ?string $at = null): self
    {
        $entry = new self();
        $entry->incident_id = $incidentId;
        $entry->entry_type = $type;
        $entry->body = $body;
        $entry->entry_at = $at ?? Actor::now();
        $entry->save(false);
        return $entry;
    }
}
