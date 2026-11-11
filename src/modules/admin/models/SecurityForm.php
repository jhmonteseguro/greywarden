<?php

namespace app\modules\admin\models;

use app\components\Mfa;
use app\models\AuditLog;
use app\models\Setting;
use yii\base\Model;

/**
 * Admin › Settings › Sign-in security: whether two-step sign-in is optional, required for
 * administrators or required for everyone. Users without a method are sent to set one up at
 * their next page (MfaGuard). Changes go to the audit log (entity "setting").
 */
class SecurityForm extends Model
{
    public $mfa_required;

    public static function current(): self
    {
        return new self(['mfa_required' => Mfa::policy()]);
    }

    public function rules(): array
    {
        return [
            ['mfa_required', 'default', 'value' => Mfa::REQUIRED_OFF],
            ['mfa_required', 'in', 'range' => array_keys(Mfa::requiredOptions())],
        ];
    }

    public function attributeLabels(): array
    {
        return ['mfa_required' => 'Two-step sign-in'];
    }

    public function attributeHints(): array
    {
        return ['mfa_required' => 'Authenticator app or passkey. When required, users without one are sent to set it up before they can do anything else.'];
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $before = Mfa::policy();
        $value = (string) $this->mfa_required;
        if ($value !== $before) {
            Setting::put([Mfa::SETTING_REQUIRED => $value]);
            $labels = Mfa::requiredOptions();
            AuditLog::record('setting', null, 'Sign-in security', 'update', ['mfa_required' => [$labels[$before], $labels[$value]]]);
        }
        return true;
    }
}
