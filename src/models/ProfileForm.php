<?php

namespace app\models;

use yii\base\Model;

/**
 * Edit own profile (name, email, job title).
 */
class ProfileForm extends Model
{
    public $full_name;
    public $email;
    public $job_title;

    private User $user;

    public function __construct(User $user, $config = [])
    {
        $this->user = $user;
        $this->full_name = $user->full_name;
        $this->email = $user->email;
        $this->job_title = $user->job_title;
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            [['full_name', 'email', 'job_title'], 'trim'],
            [['full_name', 'email'], 'required'],
            [['full_name', 'job_title'], 'string', 'max' => 128],
            ['email', 'email'],
            ['email', 'unique', 'targetClass' => User::class, 'filter' => ['<>', 'id', $this->user->id]],
        ];
    }

    public function attributeLabels(): array
    {
        return (new User())->attributeLabels();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $this->user->full_name = $this->full_name;
        $this->user->email = $this->email;
        $this->user->job_title = $this->job_title ?: null;
        return $this->user->save(false);
    }
}
