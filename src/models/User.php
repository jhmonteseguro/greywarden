<?php

namespace app\models;

use app\behaviors\AuditBehavior;
use app\components\Actor;
use app\components\Rbac;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * Application user (DB-backed, bcrypt password hashes).
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string $full_name
 * @property string|null $job_title
 * @property string $password_hash
 * @property string $auth_key
 * @property int $status
 * @property bool $must_change_password
 * @property string|null $password_changed_at
 * @property string|null $last_login_at
 * @property string|null $last_login_ip
 * @property string $created_at
 * @property string $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class User extends ActiveRecord implements IdentityInterface
{
    public const STATUS_DISABLED = 0;
    public const STATUS_ACTIVE = 10;

    public static function tableName(): string
    {
        return 'user';
    }

    public static function statusLabels(): array
    {
        return [self::STATUS_ACTIVE => 'Active', self::STATUS_DISABLED => 'Disabled'];
    }

    public function behaviors(): array
    {
        return [
            'timestamp' => ['class' => TimestampBehavior::class, 'value' => static fn() => Actor::now()],
            'blameable' => ['class' => BlameableBehavior::class, 'value' => static fn() => Actor::id()],
            'audit' => [
                'class' => AuditBehavior::class,
                'entityType' => 'user',
                'ignored' => ['created_at', 'updated_at', 'created_by', 'updated_by', 'last_login_at', 'last_login_ip', 'auth_key'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['username', 'email', 'full_name'], 'required'],
            [['username', 'email', 'full_name', 'job_title'], 'trim'],
            ['username', 'string', 'min' => 3, 'max' => 64],
            ['username', 'match', 'pattern' => '/^[a-z0-9._-]+$/i', 'message' => 'Use letters, numbers, dots, dashes or underscores.'],
            ['username', 'unique'],
            ['email', 'email'],
            ['email', 'string', 'max' => 190],
            ['email', 'unique'],
            [['full_name', 'job_title'], 'string', 'max' => 128],
            ['status', 'in', 'range' => array_keys(self::statusLabels())],
            ['must_change_password', 'boolean'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'username' => 'Username',
            'email' => 'Email',
            'full_name' => 'Full name',
            'job_title' => 'Job title',
            'status' => 'Status',
            'must_change_password' => 'Must change password',
            'password_hash' => 'Password',
            'password_changed_at' => 'Password changed',
            'last_login_at' => 'Last sign-in',
            'last_login_ip' => 'Last sign-in IP',
            'created_at' => 'Created',
        ];
    }

    // ---- IdentityInterface -------------------------------------------------

    public static function findIdentity($id): ?static
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    public static function findIdentityByAccessToken($token, $type = null): ?static
    {
        return null; // no API tokens (yet)
    }

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getAuthKey(): string
    {
        return $this->auth_key;
    }

    public function validateAuthKey($authKey): bool
    {
        return hash_equals($this->auth_key, (string) $authKey);
    }

    // ---- Helpers ------------------------------------------------------------

    public static function findByUsername(string $username): ?static
    {
        return static::findOne(['username' => $username]);
    }

    public static function findByLogin(string $login): ?static
    {
        return static::find()->where(['or', ['username' => $login], ['email' => $login]])->one();
    }

    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVE;
    }

    public function validatePassword(string $password): bool
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    public function setPassword(string $password): void
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password, 12);
        $this->password_changed_at = Actor::now();
    }

    public function generateAuthKey(): void
    {
        $this->auth_key = Yii::$app->security->generateRandomString(48);
    }

    public function getRoleName(): ?string
    {
        if (!$this->id) {
            return null;
        }
        $roles = array_keys(Yii::$app->authManager->getRolesByUser($this->id));
        foreach ([Rbac::ROLE_ADMIN, Rbac::ROLE_ANALYST, Rbac::ROLE_VIEWER] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }
        return null;
    }

    public function getRoleLabel(): string
    {
        $role = $this->getRoleName();
        return $role ? (Rbac::roleLabels()[$role] ?? $role) : 'None';
    }

    /** Replace the user's role assignment (single role per user). */
    public function assignRole(string $role): void
    {
        $auth = Yii::$app->authManager;
        $old = $this->getRoleName();
        if ($old === $role) {
            return;
        }
        foreach (array_keys(Rbac::roleLabels()) as $r) {
            if ($item = $auth->getAssignment($r, $this->id)) {
                $auth->revoke($auth->getRole($r), $this->id);
            }
        }
        $auth->assign($auth->getRole($role), $this->id);
        AuditLog::record('user', $this->id, $this->getAuditLabel(), 'update', ['role' => [$old, $role]]);
    }

    public function getAuditLabel(): string
    {
        return $this->username . ' (' . $this->full_name . ')';
    }

    public function formatAuditValue(string $attribute, mixed $value): string
    {
        return match ($attribute) {
            'status' => self::statusLabels()[(int) $value] ?? (string) $value,
            'must_change_password' => $value ? 'Yes' : 'No',
            'role' => Rbac::roleLabels()[$value] ?? (string) $value,
            default => is_array($value) ? implode(', ', $value) : (string) $value,
        };
    }

    public function getDisplayName(): string
    {
        return $this->full_name ?: $this->username;
    }

    public function getInitials(): string
    {
        $parts = preg_split('/\s+/', trim($this->getDisplayName())) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        return $initials ?: '?';
    }

    /** Active users for "owner" / "assigned to" dropdowns. */
    public static function options(int|string|null $includeId = null): array
    {
        $query = static::find()->select(['full_name', 'id'])
            ->where($includeId ? ['or', ['status' => self::STATUS_ACTIVE], ['id' => $includeId]] : ['status' => self::STATUS_ACTIVE])
            ->orderBy('full_name')->indexBy('id');
        return $query->column();
    }

    public static function nameOf(int|string|null $id): ?string
    {
        if (!$id) {
            return null;
        }
        static $cache = [];
        return $cache[$id] ??= static::find()->select('full_name')->where(['id' => $id])->scalar() ?: null;
    }

    /**
     * Create a user with a role (used by migrations, console and admin UI).
     */
    public static function createUser(string $username, string $email, string $fullName, string $password, string $role, bool $mustChange = true): static
    {
        $user = new static();
        $user->username = $username;
        $user->email = $email;
        $user->full_name = $fullName;
        $user->status = self::STATUS_ACTIVE;
        $user->must_change_password = $mustChange;
        $user->setPassword($password);
        $user->generateAuthKey();
        if (!$user->save()) {
            throw new \RuntimeException('Unable to create user: ' . json_encode($user->getErrors()));
        }
        Yii::$app->authManager->assign(Yii::$app->authManager->getRole($role), $user->id);
        return $user;
    }
}
