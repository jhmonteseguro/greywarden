<?php

namespace app\models;

use app\components\Actor;
use app\components\Totp;
use yii\base\Model;

/**
 * Setting up an authenticator app: the user scans the secret and enters the first code it
 * shows, which proves the app has it before two-step sign-in starts asking for codes.
 */
class TotpSetupForm extends Model
{
    public $code;

    public function __construct(private readonly UserTotp $totp, array $config = [])
    {
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            ['code', 'required', 'message' => 'Enter the 6-digit code the app shows.'],
            ['code', 'trim'],
            ['code', 'string', 'max' => 16],
        ];
    }

    public function attributeLabels(): array
    {
        return ['code' => 'Code from the app'];
    }

    public function confirm(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $secret = $this->totp->getPlainSecret();
        $counter = $secret === null ? null : Totp::verify($secret, (string) $this->code, time());
        if ($counter === null) {
            $this->addError('code', 'That code is not right. Check the time on your phone and try the next code.');
            return false;
        }
        $this->totp->updateAttributes(['confirmed_at' => Actor::now(), 'last_counter' => $counter]);
        return true;
    }
}
