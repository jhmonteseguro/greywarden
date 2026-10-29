<?php

namespace app\modules\supplier\models;

use app\components\Actor;
use app\models\AuditLog;
use DateTimeImmutable;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * Assurance a supplier has given: a certificate (ISO 27001, Cyber Essentials),
 * an audit report (SOC 2), a penetration test summary or a questionnaire, with
 * the date it runs out. Entries are added and removed, never edited; both are
 * logged in the supplier's history.
 *
 * @property int $id
 * @property int $supplier_id
 * @property string $type
 * @property string|null $description
 * @property string|null $issued_on
 * @property string|null $expires_on
 * @property string|null $evidence
 * @property string $created_at
 * @property int|null $created_by
 *
 * @property-read Supplier $supplier
 */
class SupplierAssurance extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'supplier_assurance';
    }

    public static function typeOptions(): array
    {
        return [
            'iso27001' => 'ISO/IEC 27001 certificate',
            'soc2_type2' => 'SOC 2 Type II report',
            'soc2_type1' => 'SOC 2 Type I report',
            'cyber_essentials' => 'Cyber Essentials',
            'cyber_essentials_plus' => 'Cyber Essentials Plus',
            'pci_dss' => 'PCI DSS attestation (AOC)',
            'iso27701' => 'ISO/IEC 27701 certificate',
            'csa_star' => 'CSA STAR',
            'pentest' => 'Penetration test summary',
            'questionnaire' => 'Security questionnaire',
            'other' => 'Other',
        ];
    }

    public static function expiryWarningDays(): int
    {
        return (int) (Yii::$app->params['supplier.assuranceWarningDays'] ?? 60);
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
            [['description', 'evidence'], 'trim'],
            ['type', 'required'],
            ['type', 'in', 'range' => array_keys(self::typeOptions())],
            ['description', 'string', 'max' => 255],
            ['evidence', 'string', 'max' => 512],
            [['issued_on', 'expires_on'], 'date', 'format' => 'php:Y-m-d'],
            [['issued_on', 'expires_on', 'description', 'evidence'], 'default', 'value' => null],
            ['issued_on', 'compare', 'compareValue' => date('Y-m-d'), 'operator' => '<=', 'type' => 'string', 'message' => 'The issue date cannot be in the future.'],
            ['expires_on', 'compare', 'compareAttribute' => 'issued_on', 'operator' => '>', 'type' => 'string', 'skipOnEmpty' => true,
                'when' => fn() => (bool) $this->issued_on, 'message' => 'The expiry date must be after the issue date.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'type' => 'Type',
            'description' => 'Scope / details',
            'issued_on' => 'Issued',
            'expires_on' => 'Expires',
            'evidence' => 'Evidence',
            'created_at' => 'Recorded',
            'created_by' => 'Recorded by',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'description' => 'e.g. certificate number, what the scope covers, or the report period.',
            'expires_on' => 'SOC 2 reports and pen tests: 12 months after the period / test ends. Leave empty if it does not expire.',
            'evidence' => 'Link or location of the document, or attach it on the Files tab.',
        ];
    }

    public function getSupplier()
    {
        return $this->hasOne(Supplier::class, ['id' => 'supplier_id']);
    }

    public function getTypeLabel(): string
    {
        return self::typeOptions()[$this->type] ?? (string) $this->type;
    }

    public function isExpired(?string $today = null): bool
    {
        return $this->expires_on !== null && $this->expires_on < ($today ?? date('Y-m-d'));
    }

    /** Expires within the warning window and has not expired yet */
    public function expiresSoon(?string $today = null): bool
    {
        $today ??= date('Y-m-d');
        return $this->expires_on !== null && $this->expires_on >= $today
            && $this->expires_on <= (new DateTimeImmutable($today))->modify('+' . self::expiryWarningDays() . ' days')->format('Y-m-d');
    }

    /** Lasts longer than $other (no expiry date lasts longest) */
    public function outlasts(self $other): bool
    {
        return ($this->expires_on ?? '9999-12-31') > ($other->expires_on ?? '9999-12-31');
    }

    public function getAuditText(): string
    {
        $fmt = Yii::$app->formatter;
        return trim(implode(' · ', array_filter([
            $this->getTypeLabel(),
            $this->description,
            $this->expires_on ? 'expires ' . $fmt->asDate($this->expires_on) : 'no expiry date',
        ])));
    }

    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);
        if ($insert && ($supplier = $this->supplier)) {
            AuditLog::record(Supplier::entityType(), $supplier->id, $supplier->getAuditLabel(), 'assurance', ['assurance' => [null, $this->getAuditText()]]);
        }
    }

    public function afterDelete(): void
    {
        parent::afterDelete();
        if ($supplier = Supplier::findOne($this->supplier_id)) {
            AuditLog::record(Supplier::entityType(), $supplier->id, $supplier->getAuditLabel(), 'assurance_remove', ['assurance' => [$this->getAuditText(), null]]);
        }
    }
}
