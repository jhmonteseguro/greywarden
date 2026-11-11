<?php

namespace app\modules\action\models;

use app\components\Actor;
use app\models\DomainRecord;
use app\models\User;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\intel\models\EdgeFinding;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;

/**
 * A remediation action: something someone has to do, by a date, to fix a weakness. Raised
 * from the record that found it (a risk's treatment, a failed control test, an incident's
 * lessons learned, a supplier review, a cloud or edge finding...) or on its own (an audit or
 * pen test finding). Closing it needs the outcome: what was done and where the evidence is.
 *
 * @property int $id
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property int|null $owner_id
 * @property string|null $due_date
 * @property string|null $record_type entity type of the record it came from (AuditLog::entities() key)
 * @property int|null $record_id
 * @property string|null $outcome
 * @property string|null $completed_at
 * @property int|null $completed_by
 *
 * @property-read User|null $owner
 * @property-read User|null $completer
 */
class Action extends DomainRecord
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses still waiting for work */
    public const OPEN_STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_CRITICAL = 'critical';

    /** Days ahead that count as "due soon" */
    public const DUE_SOON_DAYS = 14;

    /** Records an action can come from: entity type => model class */
    public const RECORD_CLASSES = [
        'risk' => Risk::class,
        'control' => Control::class,
        'decision' => Decision::class,
        'incident' => Incident::class,
        'device' => Device::class,
        'cloud_asset' => CloudAsset::class,
        'cloud_account' => CloudAccount::class,
        'supplier' => Supplier::class,
        'edge-finding' => EdgeFinding::class,
    ];

    private ?DomainRecord $recordCache = null;
    private bool $recordLoaded = false;

    public static function tableName(): string
    {
        return 'action';
    }

    public static function entityType(): string
    {
        return 'action';
    }

    public static function entityLabel(): string
    {
        return 'Action';
    }

    public static function referencePrefix(): ?string
    {
        return 'ACT';
    }

    public static function viewRoute(): string
    {
        return '/action/action/view';
    }

    public static function find(): ActionQuery
    {
        return new ActionQuery(static::class);
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_OPEN => 'Open',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_DONE => 'Done',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public static function priorityOptions(): array
    {
        return [
            self::PRIORITY_CRITICAL => 'Critical',
            self::PRIORITY_HIGH => 'High',
            self::PRIORITY_MEDIUM => 'Medium',
            self::PRIORITY_LOW => 'Low',
        ];
    }

    /** entity type => label ("Risk", "Control"...) */
    public static function recordTypeOptions(): array
    {
        $options = [];
        foreach (self::RECORD_CLASSES as $type => $class) {
            $options[$type] = $class::entityLabel();
        }
        return $options;
    }

    public static function takesActions(string $entityType): bool
    {
        return isset(self::RECORD_CLASSES[$entityType]);
    }

    public function enumAttributes(): array
    {
        return ['status' => self::statusOptions(), 'priority' => self::priorityOptions(), 'record_type' => self::recordTypeOptions()];
    }

    public function userAttributes(): array
    {
        return array_merge(parent::userAttributes(), ['owner_id', 'completed_by']);
    }

    public function formatAuditValue(string $attribute, mixed $value): string
    {
        if ($attribute === 'record_id' && $value && isset(self::RECORD_CLASSES[$this->record_type])) {
            $class = self::RECORD_CLASSES[$this->record_type];
            return (string) ($class::find()->select($class::referenceAttribute())->where(['id' => $value])->scalar() ?: '#' . $value);
        }
        return parent::formatAuditValue($attribute, $value);
    }

    public function rules(): array
    {
        return [
            [['title', 'description', 'outcome'], 'trim'],
            [['title', 'owner_id', 'status', 'priority'], 'required'],
            ['title', 'string', 'max' => 255],
            [['description', 'outcome'], 'string', 'max' => 20000],
            ['status', 'in', 'range' => array_keys(self::statusOptions())],
            ['priority', 'in', 'range' => array_keys(self::priorityOptions())],
            ['due_date', 'date', 'format' => 'php:Y-m-d'],
            ['owner_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            ['recordKey', 'safe'],
            ['record_type', 'in', 'range' => array_keys(self::RECORD_CLASSES)],
            ['record_id', 'integer'],
            ['record_id', 'required', 'when' => fn() => (string) $this->record_type !== '', 'message' => 'Choose the record.'],
            ['record_id', 'validateRecord'],
            ['outcome', 'required', 'when' => fn() => $this->status === self::STATUS_DONE,
                'whenClient' => "function(){return $('#action-status').val()==='done';}",
                'message' => 'Say what was done, and where the evidence is.'],
            ['outcome', 'required', 'when' => fn() => $this->status === self::STATUS_CANCELLED,
                'whenClient' => "function(){return $('#action-status').val()==='cancelled';}",
                'message' => 'Say why the action was cancelled.'],
        ];
    }

    public function validateRecord(string $attribute): void
    {
        if ((string) $this->record_type === '' || $this->record_id === null || $this->record_id === '') {
            return;
        }
        // An unchanged link stays valid even after its record was hard-deleted, so the action can still be closed
        if (!$this->isNewRecord && !$this->isAttributeChanged('record_type') && !$this->isAttributeChanged('record_id', false)) {
            return;
        }
        $class = self::RECORD_CLASSES[$this->record_type] ?? null;
        if ($class === null || !$class::find()->where(['id' => (int) $this->record_id])->exists()) {
            $this->addError($attribute, 'That record does not exist.');
        }
    }

    public function beforeValidate(): bool
    {
        foreach (['due_date', 'record_type', 'record_id', 'owner_id'] as $a) {
            if ($this->$a === '') {
                $this->$a = null;
            }
        }
        if ($this->record_type === null) {
            $this->record_id = null;
        }
        return parent::beforeValidate();
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        if ($this->isOpen()) {
            $this->completed_at = null;
            $this->completed_by = null;
        } elseif ($this->completed_at === null || $this->isAttributeChanged('status')) {
            $this->completed_at = Actor::now();
            $this->completed_by = Actor::id();
        }
        $this->recordLoaded = false;
        return true;
    }

    public function attributeLabels(): array
    {
        return parent::attributeLabels() + [
            'reference' => 'Reference',
            'title' => 'Action',
            'description' => 'What needs doing',
            'status' => 'Status',
            'priority' => 'Priority',
            'owner_id' => 'Owner',
            'due_date' => 'Due',
            'record_type' => 'Raised from',
            'recordKey' => 'Raised from',
            'record_id' => 'Record',
            'outcome' => 'Outcome',
            'completed_at' => 'Closed',
            'completed_by' => 'Closed by',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'outcome' => 'Needed to close the action: what was done, and where the evidence is (a ticket, a file on the Files tab...).',
            'recordKey' => 'The risk, control, incident or other record this action fixes. Leave empty for a finding from an audit or test.',
        ];
    }

    public function getOwner()
    {
        return $this->hasOne(User::class, ['id' => 'owner_id']);
    }

    public function getCompleter()
    {
        return $this->hasOne(User::class, ['id' => 'completed_by']);
    }

    /** The record the action came from, or null */
    public function getRecord(): ?DomainRecord
    {
        if (!$this->recordLoaded) {
            $class = self::RECORD_CLASSES[$this->record_type] ?? null;
            $this->recordCache = $class !== null && $this->record_id ? $class::findOne((int) $this->record_id) : null;
            $this->recordLoaded = true;
        }
        return $this->recordCache;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isOverdue(?string $today = null): bool
    {
        return $this->isOpen() && !$this->getIsArchived() && $this->due_date !== null && $this->due_date < ($today ?? date('Y-m-d'));
    }

    public function isDueSoon(?string $today = null): bool
    {
        $today ??= date('Y-m-d');
        return $this->isOpen() && !$this->getIsArchived() && $this->due_date !== null && !$this->isOverdue($today)
            && $this->due_date <= date('Y-m-d', strtotime($today . ' +' . self::DUE_SOON_DAYS . ' days'));
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function getPriorityLabel(): string
    {
        return self::priorityOptions()[$this->priority] ?? (string) $this->priority;
    }

    /** Pre-fills a new action from the record it is raised from */
    public function raiseFrom(DomainRecord $record): void
    {
        $this->record_type = $record::entityType();
        $this->record_id = $record->id;
        $owner = $record->hasAttribute('owner_id') ? $record->getAttribute('owner_id')
            : ($record->hasAttribute('assigned_to_id') ? $record->getAttribute('assigned_to_id') : null);
        if ($owner) {
            $this->owner_id = (int) $owner;
        }
        if ($record instanceof Incident || $record instanceof EdgeFinding) {
            $this->priority = in_array($record->getAttribute($record instanceof Incident ? 'severity' : 'priority'), ['critical', 'high'], true)
                ? self::PRIORITY_HIGH : self::PRIORITY_MEDIUM;
        }
    }

    /** Linked to a record that has since been hard-deleted */
    public function isRecordDeleted(): bool
    {
        return isset(self::RECORD_CLASSES[$this->record_type]) && $this->record_id && $this->getRecord() === null;
    }

    /** "Risk #12 (deleted)" for a link whose record is gone */
    public function getDeletedRecordLabel(): string
    {
        return self::RECORD_CLASSES[$this->record_type]::entityLabel() . ' #' . $this->record_id . ' (deleted)';
    }

    /** "risk:12" for the form's single "Raised from" dropdown; '' when standalone */
    public function getRecordKey(): string
    {
        return $this->record_type && $this->record_id ? $this->record_type . ':' . $this->record_id : '';
    }

    public function setRecordKey(mixed $value): void
    {
        $parts = explode(':', (string) $value, 2);
        if (count($parts) === 2 && isset(self::RECORD_CLASSES[$parts[0]]) && ctype_digit($parts[1])) {
            [$this->record_type, $this->record_id] = [$parts[0], (int) $parts[1]];
        } else {
            [$this->record_type, $this->record_id] = [null, null];
        }
    }

    /** Grouped options for "Raised from": live records of each type, plus the current one */
    public function recordOptions(): array
    {
        $options = [];
        foreach (self::RECORD_CLASSES as $type => $class) {
            $group = [];
            foreach ($class::selectOptions($this->record_type === $type && $this->record_id ? [(int) $this->record_id] : []) as $id => $label) {
                $group[$type . ':' . $id] = $label;
            }
            if ($this->record_type === $type && !$this->isNewRecord && $this->isRecordDeleted()) {
                $group[$this->getRecordKey()] = $this->getDeletedRecordLabel();   // keep the link on save
            }
            if ($group) {
                $options[$class::entityLabel()] = $group;
            }
        }
        return $options;
    }

    /** Options for the Owner dropdown: active users, plus the current owner */
    public function ownerOptions(): array
    {
        return User::options($this->owner_id);
    }

    public static function dueSoonDate(?string $today = null): string
    {
        return date('Y-m-d', strtotime(($today ?? date('Y-m-d')) . ' +' . self::DUE_SOON_DAYS . ' days'));
    }

    public static function countOpenFor(DomainRecord $record): int
    {
        return (int) self::find()->active()->forRecord($record)->open()->count();
    }
}
