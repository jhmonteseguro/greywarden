<?php

namespace app\modules\admin\models;

use app\components\Rbac;
use app\models\PasswordPolicy;
use app\models\User;
use Yii;
use yii\base\Model;

/**
 * Admin create/edit user form (incl. role assignment).
 */
class UserForm extends Model
{
    public $username;
    public $email;
    public $full_name;
    public $job_title;
    public $role = Rbac::ROLE_VIEWER;
    public $status = User::STATUS_ACTIVE;
    public $password;
    public $must_change_password = true;

    private User $user;

    public function __construct(?User $user = null, $config = [])
    {
        $this->user = $user ?? new User();
        if (!$this->user->isNewRecord) {
            $this->username = $this->user->username;
            $this->email = $this->user->email;
            $this->full_name = $this->user->full_name;
            $this->job_title = $this->user->job_title;
            $this->role = $this->user->getRoleName() ?? Rbac::ROLE_VIEWER;
            $this->status = (int) $this->user->status;
            $this->must_change_password = (bool) $this->user->must_change_password;
        }
        parent::__construct($config);
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getIsNew(): bool
    {
        return $this->user->isNewRecord;
    }

    public function rules(): array
    {
        return [
            [['username', 'email', 'full_name', 'job_title'], 'trim'],
            [['username', 'email', 'full_name', 'role', 'status'], 'required'],
            ['username', 'string', 'min' => 3, 'max' => 64],
            ['username', 'match', 'pattern' => '/^[a-z0-9._-]+$/i', 'message' => 'Use letters, numbers, dots, dashes or underscores.'],
            ['username', 'unique', 'targetClass' => User::class, 'filter' => $this->user->isNewRecord ? null : ['<>', 'id', $this->user->id]],
            ['email', 'email'],
            ['email', 'string', 'max' => 190],
            ['email', 'unique', 'targetClass' => User::class, 'filter' => $this->user->isNewRecord ? null : ['<>', 'id', $this->user->id]],
            [['full_name', 'job_title'], 'string', 'max' => 128],
            ['role', 'in', 'range' => array_keys(Rbac::roleLabels())],
            ['status', 'in', 'range' => array_keys(User::statusLabels())],
            ['must_change_password', 'boolean'],
            ['password', 'required', 'when' => fn() => $this->user->isNewRecord, 'whenClient' => 'function(){return ' . ($this->user->isNewRecord ? 'true' : 'false') . ';}'],
            ['password', PasswordPolicy::class],
            ['role', 'validateSelf'],
        ];
    }

    /** Prevent an admin from locking themselves out. */
    public function validateSelf(string $attribute): void
    {
        if ($this->user->isNewRecord || (int) $this->user->id !== (int) Yii::$app->user->id) {
            return;
        }
        if ($this->role !== Rbac::ROLE_ADMIN) {
            $this->addError('role', 'You cannot remove your own admin role.');
        }
        if ((int) $this->status !== User::STATUS_ACTIVE) {
            $this->addError('status', 'You cannot disable your own account.');
        }
    }

    public function attributeLabels(): array
    {
        return (new User())->attributeLabels() + [
            'role' => 'Role',
            'password' => 'Initial password',
            'must_change_password' => 'Require password change at next sign-in',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'password' => PasswordPolicy::describe(),
            'role' => 'Admin: everything · Analyst: create/edit records · Viewer: read-only',
        ];
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $user = $this->user;
        $user->username = $this->username;
        $user->email = $this->email;
        $user->full_name = $this->full_name;
        $user->job_title = $this->job_title ?: null;
        $user->status = (int) $this->status;
        $user->must_change_password = (bool) $this->must_change_password;
        if ($user->isNewRecord) {
            $user->setPassword($this->password);
            $user->generateAuthKey();
        }
        if ((int) $user->getOldAttribute('status') === User::STATUS_ACTIVE && (int) $user->status !== User::STATUS_ACTIVE) {
            $user->generateAuthKey(); // invalidate sessions/cookies
        }
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $user->save(false);
            $user->assignRole($this->role);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return true;
    }
}
