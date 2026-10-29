<?php

namespace app\models;

use app\components\Actor;
use app\components\Rbac;
use Yii;
use yii\base\InvalidConfigException;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A short dated note on a record, e.g. "Emailed DevOps requesting the firewall
 * change, copy saved in Files". Created only through add(), stamped with the
 * time and author, never edited, and written into the owner's history. A note
 * may link one of the owner's files; the file's name is kept on the note so it
 * still reads correctly after the file is removed.
 *
 * @property int $id
 * @property string $entity_type
 * @property int $entity_id
 * @property string $body
 * @property int|null $attachment_id
 * @property string|null $attachment_name
 * @property string $created_at
 * @property int|null $created_by
 *
 * @property-read User|null $creator
 * @property-read Attachment|null $attachment
 */
class RecordNote extends ActiveRecord
{
    /**
     * Records that take notes (keys of AuditLog::entities()). Incidents keep
     * their dated narrative on the incident timeline instead.
     */
    public const ENTITY_TYPES = ['risk', 'control', 'decision', 'cloud_account', 'cloud_asset', 'supplier'];

    public const MAX_LENGTH = 1000;

    public static function tableName(): string
    {
        return 'record_note';
    }

    public function behaviors(): array
    {
        return [
            'timestamp' => ['class' => TimestampBehavior::class, 'updatedAtAttribute' => false, 'value' => static fn() => Actor::now()],
            'blameable' => ['class' => BlameableBehavior::class, 'updatedByAttribute' => false, 'value' => static fn() => Actor::id()],
        ];
    }

    public function rules(): array
    {
        return [
            ['body', 'trim'],
            ['body', 'required', 'message' => 'Write the note.'],
            ['body', 'string', 'max' => self::MAX_LENGTH, 'tooLong' => 'Keep notes to ' . self::MAX_LENGTH . ' characters.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'body' => 'Note',
            'attachment_id' => 'File',
            'created_at' => 'Added',
            'created_by' => 'Added by',
        ];
    }

    // ---- Owners -------------------------------------------------------------

    public static function takesNotes(string $entityType): bool
    {
        return in_array($entityType, self::ENTITY_TYPES, true);
    }

    public static function findOwner(string $entityType, int $id): ?DomainRecord
    {
        return self::takesNotes($entityType) ? AuditLog::entities()[$entityType][1]::findOne($id) : null;
    }

    public function getOwner(): ?DomainRecord
    {
        return self::findOwner($this->entity_type, $this->entity_id);
    }

    /** @return self[] newest first, or oldest first for a document that reads as a narrative */
    public static function listFor(DomainRecord $owner, bool $oldestFirst = false): array
    {
        $order = $oldestFirst ? SORT_ASC : SORT_DESC;
        return self::find()->with(['creator', 'attachment'])
            ->where(['entity_type' => $owner::entityType(), 'entity_id' => $owner->id])
            ->orderBy(['created_at' => $order, 'id' => $order])->all();
    }

    public function getCreator()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getAttachment()
    {
        return $this->hasOne(Attachment::class, ['id' => 'attachment_id']);
    }

    // ---- Adding and removing ------------------------------------------------

    /**
     * Problem with a note's text, or null when it can be added.
     */
    public static function check(?string $body): ?string
    {
        $note = new self(['body' => $body]);
        return $note->validate(['body']) ? null : $note->getFirstError('body');
    }

    /**
     * Adds a note to $owner, optionally linking one of its files, and logs it in
     * the owner's history. Returns the note, or the reason it was refused.
     */
    public static function add(DomainRecord $owner, ?string $body, ?Attachment $file = null): self|string
    {
        if (!self::takesNotes($owner::entityType())) {
            throw new InvalidConfigException(sprintf('Records of type "%s" do not take notes.', $owner::entityType()));
        }
        if ($file !== null && ($file->entity_type !== $owner::entityType() || (int) $file->entity_id !== (int) $owner->id)) {
            return 'That file is not attached to ' . $owner->getReferenceValue() . '.';
        }
        $note = new self([
            'entity_type' => $owner::entityType(),
            'entity_id' => $owner->id,
            'body' => $body,
            'attachment_id' => $file?->id,
            'attachment_name' => $file?->original_name,
        ]);
        if (!$note->validate()) {
            return $note->getFirstError('body');
        }
        if (!$note->save(false)) {
            return 'The note could not be recorded.';
        }
        AuditLog::record($owner::entityType(), $owner->id, $owner->getAuditLabel(), 'note', ['note' => [null, $note->getAuditText()]]);
        return $note;
    }

    /** The author may take back their own note; administrators may remove any. */
    public function canRemove(): bool
    {
        return Rbac::canDelete() || (Rbac::canManage() && $this->created_by !== null && (int) $this->created_by === Actor::id());
    }

    /** Removes the note and logs its text in the owner's history. */
    public function remove(): bool
    {
        $owner = $this->getOwner();
        if (!$this->delete()) {
            return false;
        }
        if ($owner) {
            AuditLog::record($owner::entityType(), $owner->id, $owner->getAuditLabel(), 'note_remove', ['note' => [$this->getAuditText(), null]]);
        }
        return true;
    }

    /** Hard delete of a record: its notes go with it. */
    public static function deleteAllFor(string $entityType, int $entityId): void
    {
        self::deleteAll(['entity_type' => $entityType, 'entity_id' => $entityId]);
    }

    /** Session key holding the text of a refused note, so the form can offer it again */
    public static function draftKey(DomainRecord $owner): string
    {
        return 'note-draft.' . $owner::entityType() . '.' . $owner->id;
    }

    /** Takes back the refused note's text, if any (once) */
    public static function takeDraft(DomainRecord $owner): string
    {
        if (!Yii::$app->has('session')) {
            return '';
        }
        $draft = (string) Yii::$app->session->get(self::draftKey($owner), '');
        Yii::$app->session->remove(self::draftKey($owner));
        return $draft;
    }

    // ---- Display ------------------------------------------------------------

    /** The linked file's name, whether or not the file is still attached */
    public function getFileName(): ?string
    {
        return $this->attachment->original_name ?? $this->attachment_name;
    }

    public function getIsFileRemoved(): bool
    {
        return $this->attachment_name !== null && $this->attachment === null;
    }

    public function getAuditText(): string
    {
        $text = $this->body;
        if ($name = $this->getFileName()) {
            $text .= ' · file: ' . $name;
        }
        return $text;
    }
}
