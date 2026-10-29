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
 * A completed periodic review of a risk, decision or supplier. Created only through
 * record(), which also moves the record's review date on to the next review
 * date and writes one "Review recorded" entry into the record's history.
 * Reviews are never edited; the reviewer or an administrator may remove one
 * (the record's review date is left as it is, and the history keeps the text).
 *
 * @property int $id
 * @property string $entity_type
 * @property int $entity_id
 * @property string $reviewed_on
 * @property int|null $reviewed_by_id
 * @property string $outcome
 * @property string|null $due_on
 * @property string|null $next_review_date
 * @property string $created_at
 * @property int|null $created_by
 *
 * @property-read User|null $reviewedBy
 * @property-read User|null $creator
 */
class RecordReview extends ActiveRecord
{
    /** Reviewed records => [review date attribute, default interval to the next review] */
    public const ENTITY_TYPES = [
        'risk' => ['next_review_date', '+90 days'],
        'decision' => ['review_date', '+1 year'],
        'supplier' => ['next_review_date', '+12 months'],
    ];

    public const MAX_LENGTH = 2000;

    /** Set by record(): the record under review, used by the validators */
    private ?DomainRecord $owner = null;

    public static function tableName(): string
    {
        return 'record_review';
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
            ['outcome', 'trim'],
            [['reviewed_on', 'outcome'], 'required'],
            ['outcome', 'string', 'max' => self::MAX_LENGTH, 'tooLong' => 'Keep the outcome to ' . self::MAX_LENGTH . ' characters.'],
            [['reviewed_on', 'next_review_date'], 'date', 'format' => 'php:Y-m-d'],
            ['reviewed_on', 'compare', 'compareValue' => date('Y-m-d'), 'operator' => '<=', 'type' => 'string', 'message' => 'The review date cannot be in the future.'],
            ['reviewed_by_id', 'default', 'value' => Actor::id()],
            ['reviewed_by_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            ['next_review_date', 'default', 'value' => null],
            ['next_review_date', 'required', 'when' => fn() => $this->owner?->isReviewable() ?? false,
                'message' => 'Set the next review date, or close the record if it no longer needs reviewing.'],
            ['next_review_date', 'compare', 'compareAttribute' => 'reviewed_on', 'operator' => '>', 'type' => 'string',
                'message' => 'The next review date must be after the review date.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'reviewed_on' => 'Reviewed on',
            'reviewed_by_id' => 'Reviewed by',
            'outcome' => 'Outcome',
            'due_on' => 'Was due',
            'next_review_date' => 'Next review date',
            'created_at' => 'Recorded',
            'created_by' => 'Recorded by',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'outcome' => 'What was checked and concluded, e.g. "Still valid, no change to the scores" or "Likelihood lowered after MFA rollout".',
            'next_review_date' => 'Becomes the record\'s review date.',
        ];
    }

    // ---- Reviewed records ---------------------------------------------------

    public static function takesReviews(string $entityType): bool
    {
        return isset(self::ENTITY_TYPES[$entityType]);
    }

    public static function findOwner(string $entityType, int $id): ?DomainRecord
    {
        return self::takesReviews($entityType) ? AuditLog::entities()[$entityType][1]::findOne($id) : null;
    }

    public function getOwner(): ?DomainRecord
    {
        return self::findOwner($this->entity_type, $this->entity_id);
    }

    /** Name of the owner's review date attribute */
    public static function dateAttribute(DomainRecord $owner): string
    {
        return self::ENTITY_TYPES[$owner::entityType()][0] ?? throw new InvalidConfigException(sprintf('Records of type "%s" are not reviewed.', $owner::entityType()));
    }

    /** A new, unsaved review of $owner with today's date, the current user and the usual next review date (the record's own interval when it has one) */
    public static function draft(DomainRecord $owner): self
    {
        $interval = self::ENTITY_TYPES[$owner::entityType()][1] ?? throw new InvalidConfigException(sprintf('Records of type "%s" are not reviewed.', $owner::entityType()));
        $interval = $owner->reviewInterval() ?? $interval;
        $today = Actor::today();
        return new self([
            'reviewed_on' => $today,
            'reviewed_by_id' => Actor::id(),
            'next_review_date' => $owner->isReviewable() ? date('Y-m-d', strtotime($interval, strtotime($today))) : null,
        ]);
    }

    /** @return self[] most recent review first */
    public static function listFor(DomainRecord $owner): array
    {
        return self::find()->with(['reviewedBy', 'creator'])
            ->where(['entity_type' => $owner::entityType(), 'entity_id' => $owner->id])
            ->orderBy(['reviewed_on' => SORT_DESC, 'id' => SORT_DESC])->all();
    }

    public static function latestFor(DomainRecord $owner): ?self
    {
        return self::listFor($owner)[0] ?? null;
    }

    public function getReviewedBy()
    {
        return $this->hasOne(User::class, ['id' => 'reviewed_by_id']);
    }

    public function getCreator()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    // ---- Recording and removing ---------------------------------------------

    /**
     * Records a review of $owner from the posted $data (reviewed_on,
     * reviewed_by_id, outcome, next_review_date), sets the owner's review date
     * to the next review date and logs it in the owner's history. Returns the
     * review; check hasErrors() for a refused one.
     */
    public static function record(DomainRecord $owner, array $data): self
    {
        $attribute = self::dateAttribute($owner);
        $review = new self();
        $review->owner = $owner;
        $review->setAttributes(array_intersect_key($data, array_flip(['reviewed_on', 'reviewed_by_id', 'outcome', 'next_review_date'])), false);
        $review->entity_type = $owner::entityType();
        $review->entity_id = $owner->id;
        $review->due_on = $owner->$attribute ?: null;
        if (!$review->validate()) {
            return $review;
        }
        $transaction = self::getDb()->beginTransaction();
        try {
            $review->save(false);
            // One history entry for the review, not a separate "Updated" for the date
            $owner->updateAttributes([$attribute => $review->next_review_date, 'updated_at' => Actor::now(), 'updated_by' => Actor::id()]);
            $changes = ['review' => [null, $review->getAuditText()]];
            if ($review->due_on !== $review->next_review_date) {
                $changes[$attribute] = [$review->due_on, $review->next_review_date];
            }
            AuditLog::record($owner::entityType(), $owner->id, $owner->getAuditLabel(), 'review', $changes);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $review;
    }

    /** The reviewer or whoever recorded it may take it back; administrators may remove any. */
    public function canRemove(): bool
    {
        $me = Actor::id();
        return Rbac::canDelete() || (Rbac::canManage() && $me !== null
            && ((int) $this->created_by === $me || (int) $this->reviewed_by_id === $me));
    }

    /** Removes the review and logs its text in the owner's history. The owner's review date stays. */
    public function remove(): bool
    {
        $owner = $this->getOwner();
        if (!$this->delete()) {
            return false;
        }
        if ($owner) {
            AuditLog::record($owner::entityType(), $owner->id, $owner->getAuditLabel(), 'review_remove', ['review' => [$this->getAuditText(), null]]);
        }
        return true;
    }

    /** Hard delete of a record: its reviews go with it. */
    public static function deleteAllFor(string $entityType, int $entityId): void
    {
        self::deleteAll(['entity_type' => $entityType, 'entity_id' => $entityId]);
    }

    /** Session key holding a refused review's fields, so the form can offer them again */
    public static function draftKey(DomainRecord $owner): string
    {
        return 'review-draft.' . $owner::entityType() . '.' . $owner->id;
    }

    /** The review form's model: a refused review (once, with its errors) or a fresh draft */
    public static function formModel(DomainRecord $owner): self
    {
        $review = self::draft($owner);
        if (Yii::$app->has('session') && ($saved = Yii::$app->session->get(self::draftKey($owner)))) {
            Yii::$app->session->remove(self::draftKey($owner));
            $review->setAttributes($saved['data'] ?? [], false);
            $review->addErrors($saved['errors'] ?? []);
        }
        return $review;
    }

    // ---- Display ------------------------------------------------------------

    /** Reviewed after the date it was due */
    public function getWasLate(): bool
    {
        return $this->due_on !== null && $this->reviewed_on > $this->due_on;
    }

    public function getAuditText(): string
    {
        $fmt = Yii::$app->formatter;
        $text = 'Reviewed ' . $fmt->asDate($this->reviewed_on);
        if ($name = $this->reviewedBy->full_name ?? null) {
            $text .= ' by ' . $name;
        }
        return $text . ': ' . $this->outcome;
    }
}
