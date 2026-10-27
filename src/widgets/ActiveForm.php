<?php

namespace app\widgets;

/**
 * Project-wide ActiveForm defaults (Bootstrap 5). Forms are laid out with
 * fieldsets: <fieldset class="gw-fieldset"><legend class="gw-fieldset__title">…</legend> … </fieldset>
 */
class ActiveForm extends \yii\bootstrap5\ActiveForm
{
    public $options = ['class' => 'gw-form'];
    public $validateOnBlur = true;
    public $errorCssClass = 'is-invalid';
    public $fieldConfig = [
        'options' => ['class' => 'mb-3 gw-field'],
        'labelOptions' => ['class' => 'form-label gw-field__label'],
        'hintOptions' => ['class' => 'form-text gw-field__hint'],
    ];
}
