<?php

namespace app\widgets\grid;

use app\components\Rbac;
use yii\helpers\Html;

/**
 * View / edit icon buttons; edit is hidden unless the user may manage records.
 */
class ActionColumn extends \yii\grid\ActionColumn
{
    public $template = '{view} {update}';
    public $contentOptions = ['class' => 'gw-col-actions'];
    public $headerOptions = ['class' => 'gw-col-actions'];
    public string $permission = Rbac::PERM_MANAGE;

    public function init(): void
    {
        $this->visibleButtons += [
            'update' => fn($model) => Rbac::can($this->permission) && !(method_exists($model, 'getIsArchived') && $model->getIsArchived()),
        ];
        parent::init();
    }

    protected function initDefaultButtons(): void
    {
        $this->initButton('view', 'eye', 'View');
        $this->initButton('update', 'pencil-square', 'Edit');
    }

    protected function initButton($name, $iconName, $additionalOptions = []): void
    {
        $title = is_string($additionalOptions) ? $additionalOptions : ucfirst($name);
        if (!isset($this->buttons[$name])) {
            $this->buttons[$name] = static fn($url) => Html::a(
                Html::tag('i', '', ['class' => 'bi bi-' . $iconName, 'aria-hidden' => 'true']),
                $url,
                ['class' => 'gw-icon-btn gw-icon-btn--' . $name, 'title' => $title, 'aria-label' => $title, 'data-pjax' => 0]
            );
        }
    }
}
