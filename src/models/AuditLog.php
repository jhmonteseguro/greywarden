<?php

namespace app\models;

use app\components\Actor;
use Yii;
use yii\db\ActiveRecord;
use yii\helpers\Json;

/**
 * Immutable audit trail entry.
 *
 * @property int $id
 * @property string $entity_type
 * @property int|null $entity_id
 * @property string|null $entity_label
 * @property string $action
 * @property string|null $changes JSON {"field": [old, new]}
 * @property int|null $user_id
 * @property string|null $username
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $created_at
 *
 * @property-read User|null $user
 */
class AuditLog extends ActiveRecord
{
    public const ACTION_LABELS = [
        'create' => 'Created',
        'update' => 'Updated',
        'archive' => 'Archived',
        'restore' => 'Restored',
        'delete' => 'Deleted',
        'attach' => 'File attached',
        'detach' => 'File removed',
        'note' => 'Note added',
        'note_remove' => 'Note removed',
        'review' => 'Review recorded',
        'review_remove' => 'Review removed',
        'assurance' => 'Assurance added',
        'assurance_remove' => 'Assurance removed',
        'export' => 'Exported to PDF',
        'regenerate' => 'Figures regenerated',
        'approve' => 'Approved',
        'approval_withdraw' => 'Approval withdrawn',
        'login' => 'Signed in',
        'logout' => 'Signed out',
        'login_failed' => 'Failed sign-in',
        'login_blocked' => 'Sign-in blocked (lockout)',
        'password_change' => 'Password changed',
        'password_reset' => 'Password reset by admin',
        'unlock' => 'Sign-in lockout cleared',
    ];

    public static function tableName(): string
    {
        return 'audit_log';
    }

    /** Entity type => [label, model class, view route] */
    public static function entities(): array
    {
        return [
            'risk' => ['Risk', \app\modules\risk\models\Risk::class, '/risk/risk/view'],
            'control' => ['Control', \app\modules\control\models\Control::class, '/control/control/view'],
            'requirement' => ['Framework requirement', \app\modules\control\models\FrameworkRequirement::class, '/control/framework/requirement'],
            'decision' => ['Decision', \app\modules\decision\models\Decision::class, '/decision/decision/view'],
            'incident' => ['Incident', \app\modules\incident\models\Incident::class, '/incident/incident/view'],
            'device' => ['Device', \app\modules\inventory\models\Device::class, '/inventory/device/view'],
            'cloud_account' => ['Cloud account', \app\modules\cloud\models\CloudAccount::class, '/cloud/account/view'],
            'cloud_asset' => ['Cloud asset', \app\modules\cloud\models\CloudAsset::class, '/cloud/asset/view'],
            'supplier' => ['Supplier', \app\modules\supplier\models\Supplier::class, '/supplier/supplier/view'],
            'report' => ['Report', \app\modules\report\models\Report::class, '/report/report/view'],
            'user' => ['User', User::class, '/admin/user/view'],
            'lookup' => ['Lookup', Lookup::class, '/admin/lookup/update'],
            'setting' => ['Settings', \app\modules\admin\models\BrandingForm::class, '/admin/settings/index'],
            'blocklist' => ['Blocklist entry', \app\modules\intel\models\BlocklistEntry::class, '/intel/blocklist/view'],
            'indicator' => ['Intel indicator', \app\modules\intel\models\IntelIndicator::class, '/intel/investigate/indicator'],
            'edge-finding' => ['Edge finding', \app\modules\intel\models\EdgeFinding::class, '/intel/edge/view'],
        ];
    }

    public static function entityLabels(): array
    {
        return array_map(static fn($e) => $e[0], self::entities());
    }

    public static function record(string $entityType, ?int $entityId, ?string $label, string $action, array $changes = [], ?int $userId = null, ?string $username = null): void
    {
        $log = new self();
        $log->entity_type = $entityType;
        $log->entity_id = $entityId;
        $log->entity_label = $label !== null ? mb_substr($label, 0, 255) : null;
        $log->action = $action;
        $log->changes = $changes ? Json::encode($changes) : null;
        $log->user_id = $userId ?? Actor::id();
        $log->username = $username ?? Actor::name();
        $log->ip = Actor::ip();
        $log->user_agent = Actor::userAgent();
        $log->created_at = Actor::now();
        if (!$log->save(false)) {
            Yii::error('Unable to write audit log entry', __METHOD__);
        }
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getChangesArray(): array
    {
        if (!$this->changes) {
            return [];
        }
        try {
            $data = Json::decode($this->changes);
        } catch (\Throwable) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    public function getActionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? ucfirst(str_replace('_', ' ', $this->action));
    }

    public function getEntityLabelText(): string
    {
        return self::entities()[$this->entity_type][0] ?? ucfirst($this->entity_type);
    }

    public function getEntityUrl(): ?array
    {
        $route = self::entities()[$this->entity_type][2] ?? null;
        if (!$route || !$this->entity_id || $this->action === 'delete') {
            return null;
        }
        return [$route, 'id' => $this->entity_id];
    }

    /**
     * Human readable diff rows: [['field' => 'Status', 'old' => 'Identified', 'new' => 'Assessed'], ...]
     */
    public function getFormattedChanges(): array
    {
        $class = self::entities()[$this->entity_type][1] ?? null;
        $prototype = $class && class_exists($class) ? new $class() : null;
        $rows = [];
        foreach ($this->getChangesArray() as $field => $pair) {
            [$old, $new] = is_array($pair) && count($pair) === 2 ? array_values($pair) : [null, $pair];
            $rows[] = [
                'attribute' => $field,
                'field' => $prototype ? $prototype->getAttributeLabel($field) : $field,
                'old' => self::display($prototype, $field, $old),
                'new' => self::display($prototype, $field, $new),
            ];
        }
        return $rows;
    }

    private static function display(?object $prototype, string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if ($prototype && method_exists($prototype, 'formatAuditValue')) {
            return $prototype->formatAuditValue($field, $value);
        }
        return is_array($value) ? implode(', ', $value) : (string) $value;
    }
}
