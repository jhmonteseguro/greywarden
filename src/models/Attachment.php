<?php

namespace app\models;

use app\components\Actor;
use app\modules\control\models\ControlTest;
use Yii;
use yii\base\InvalidConfigException;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;

/**
 * A file attached to a record as evidence. Created only through attach(),
 * which checks the type from the file's content (not the browser's claim or
 * the name alone), stores it under a random name outside the web root and
 * writes "File attached" / "File removed" into the owning record's history.
 *
 * @property int $id
 * @property string $entity_type
 * @property int $entity_id
 * @property int|null $control_test_id
 * @property bool $is_evidence attached from the control's Evidence field
 * @property string $stored_name
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property string $sha256
 * @property string|null $description
 * @property string $created_at
 * @property int|null $created_by
 *
 * @property-read User|null $creator
 * @property-read ControlTest|null $controlTest
 */
class Attachment extends ActiveRecord
{
    /** Records that take attachments (keys of AuditLog::entities()) */
    public const ENTITY_TYPES = ['risk', 'control', 'decision', 'incident', 'cloud_account', 'cloud_asset'];

    /**
     * Allowed extension => [stored MIME type, MIME types finfo may report for it].
     * Office files are ZIP containers and some libmagic builds report them as such.
     */
    public const TYPES = [
        'png' => ['image/png', ['image/png']],
        'jpg' => ['image/jpeg', ['image/jpeg']],
        'jpeg' => ['image/jpeg', ['image/jpeg']],
        'pdf' => ['application/pdf', ['application/pdf']],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']],
        'csv' => ['text/csv', ['text/csv', 'text/plain', 'application/csv']],
        'txt' => ['text/plain', ['text/plain']],
    ];

    /** Types shown inline as a preview; everything else is always downloaded */
    public const PREVIEW_TYPES = ['image/png', 'image/jpeg'];

    public static function tableName(): string
    {
        return 'attachment';
    }

    public function behaviors(): array
    {
        return [
            'timestamp' => ['class' => TimestampBehavior::class, 'updatedAtAttribute' => false, 'value' => static fn() => Actor::now()],
            'blameable' => ['class' => BlameableBehavior::class, 'updatedByAttribute' => false, 'value' => static fn() => Actor::id()],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'original_name' => 'File',
            'description' => 'Description',
            'size' => 'Size',
            'created_at' => 'Added',
            'created_by' => 'Added by',
        ];
    }

    // ---- Configuration ------------------------------------------------------

    public static function storagePath(): string
    {
        return rtrim(Yii::getAlias(Yii::$app->params['attachments.path']), '/');
    }

    public static function maxSize(): int
    {
        return (int) Yii::$app->params['attachments.maxSizeMb'] * 1024 * 1024;
    }

    /** For the file input's accept attribute and hints */
    public static function extensionList(): string
    {
        return implode(', ', array_map('strtoupper', array_diff(array_keys(self::TYPES), ['jpeg'])));
    }

    public static function acceptAttribute(): string
    {
        return implode(',', array_map(static fn($e) => '.' . $e, array_keys(self::TYPES)));
    }

    // ---- Owners -------------------------------------------------------------

    /** @return class-string<DomainRecord> */
    public static function ownerClass(string $entityType): string
    {
        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            throw new InvalidConfigException("Records of type \"$entityType\" do not take attachments.");
        }
        return AuditLog::entities()[$entityType][1];
    }

    public static function findOwner(string $entityType, int $id): ?DomainRecord
    {
        return in_array($entityType, self::ENTITY_TYPES, true) ? self::ownerClass($entityType)::findOne($id) : null;
    }

    public function getOwner(): ?DomainRecord
    {
        return self::findOwner($this->entity_type, $this->entity_id);
    }

    /** @return self[] newest first */
    public static function listFor(DomainRecord $owner): array
    {
        return self::find()->with(['creator', 'controlTest'])
            ->where(['entity_type' => $owner::entityType(), 'entity_id' => $owner->id])
            ->orderBy(['id' => SORT_DESC])->all();
    }

    public static function countFor(DomainRecord $owner): int
    {
        return (int) self::find()->where(['entity_type' => $owner::entityType(), 'entity_id' => $owner->id])->count();
    }

    public function getCreator()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getControlTest()
    {
        return $this->hasOne(ControlTest::class, ['id' => 'control_test_id']);
    }

    // ---- Upload -------------------------------------------------------------

    /**
     * Problem with an upload, or null when it can be attached.
     */
    public static function check(?UploadedFile $file): ?string
    {
        if ($file === null || $file->error === UPLOAD_ERR_NO_FILE) {
            return 'Choose a file to attach.';
        }
        if (in_array($file->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file->size > self::maxSize()) {
            return sprintf('"%s" is larger than the %d MB limit.', $file->name, Yii::$app->params['attachments.maxSizeMb']);
        }
        if ($file->error !== UPLOAD_ERR_OK || !is_file($file->tempName)) {
            return sprintf('"%s" did not upload completely. Try again.', $file->name);
        }
        if ($file->size === 0) {
            return sprintf('"%s" is empty.', $file->name);
        }
        $ext = strtolower($file->extension);
        if (!isset(self::TYPES[$ext])) {
            return sprintf('"%s" is not an allowed type. Allowed: %s.', $file->name, self::extensionList());
        }
        $detected = FileHelper::getMimeType($file->tempName, null, false);
        if (!in_array($detected, self::TYPES[$ext][1], true)) {
            return sprintf('"%s" does not contain what its .%s name says (detected %s).', $file->name, $ext, $detected ?: 'unknown');
        }
        return null;
    }

    /**
     * Stores an upload against $owner (optionally as evidence of a control test,
     * or of the control itself) and logs it in the owner's history. Returns the
     * attachment, or the reason it was refused as a string.
     */
    public static function attach(DomainRecord $owner, ?UploadedFile $file, ?string $description = null, ?ControlTest $test = null, bool $evidence = false): self|string
    {
        if ($problem = self::check($file)) {
            return $problem;
        }
        $ext = strtolower($file->extension);
        $model = new self([
            'entity_type' => $owner::entityType(),
            'entity_id' => $owner->id,
            'control_test_id' => $test?->id,
            'is_evidence' => $evidence,
            'stored_name' => bin2hex(random_bytes(16)),
            'original_name' => self::cleanName($file->name),
            'mime_type' => self::TYPES[$ext][0],
            'size' => $file->size,
            'sha256' => hash_file('sha256', $file->tempName),
            'description' => ($d = trim((string) $description)) !== '' ? mb_substr($d, 0, 255) : null,
        ]);
        $path = $model->getPath();
        FileHelper::createDirectory(dirname($path), 0750);
        // Copy rather than move so tests (and CLI) can attach files that were not uploaded over HTTP
        if (!$file->saveAs($path, false)) {
            Yii::error("Unable to write attachment to $path", __METHOD__);
            return 'The file could not be stored. Ask an administrator to check the attachments folder.';
        }
        @chmod($path, 0640);
        if (!$model->save(false)) {
            @unlink($path);
            return 'The file could not be recorded.';
        }
        AuditLog::record($owner::entityType(), $owner->id, $owner->getAuditLabel(), 'attach', ['file' => [null, $model->getAuditText()]]);
        return $model;
    }

    /** Removes the file and logs it in the owner's history. */
    public function remove(): bool
    {
        $owner = $this->getOwner();
        if (!$this->delete()) {
            return false;
        }
        if ($owner) {
            AuditLog::record($owner::entityType(), $owner->id, $owner->getAuditLabel(), 'detach', ['file' => [$this->getAuditText(), null]]);
        }
        return true;
    }

    /** Hard delete of a record: its files go with it (the record's own audit entry says so). */
    public static function deleteAllFor(string $entityType, int $entityId): void
    {
        foreach (self::find()->where(['entity_type' => $entityType, 'entity_id' => $entityId])->each() as $attachment) {
            $attachment->delete();
        }
    }

    public function afterDelete(): void
    {
        parent::afterDelete();
        $path = $this->getPath();
        if (is_file($path) && !@unlink($path)) {
            Yii::warning("Unable to delete attachment file $path", __METHOD__);
        }
    }

    // ---- Display ------------------------------------------------------------

    /** Two-level fan-out keeps directories small: ab/abcdef... */
    public function getPath(): string
    {
        return self::storagePath() . '/' . substr($this->stored_name, 0, 2) . '/' . $this->stored_name;
    }

    public function getIsPreviewable(): bool
    {
        return in_array($this->mime_type, self::PREVIEW_TYPES, true);
    }

    public function getIcon(): string
    {
        return match (true) {
            str_starts_with($this->mime_type, 'image/') => 'file-earmark-image',
            $this->mime_type === 'application/pdf' => 'file-earmark-pdf',
            str_contains($this->mime_type, 'wordprocessingml') => 'file-earmark-word',
            str_contains($this->mime_type, 'spreadsheetml'), $this->mime_type === 'text/csv' => 'file-earmark-spreadsheet',
            default => 'file-earmark-text',
        };
    }

    public function getAuditText(): string
    {
        $text = sprintf('%s (%s, SHA-256 %s…)', $this->original_name, Yii::$app->formatter->asShortSize($this->size, 1), substr($this->sha256, 0, 12));
        if ($this->control_test_id && ($test = $this->controlTest)) {
            $text .= ' · test of ' . Yii::$app->formatter->asDate($test->tested_on);
        } elseif ($this->is_evidence) {
            $text .= ' · evidence';
        }
        return $text;
    }

    /** Keeps a readable name for downloads while dropping paths and control characters. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? 'file';
        return mb_substr(trim($name) !== '' ? trim($name) : 'file', -255);
    }
}
