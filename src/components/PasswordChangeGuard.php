<?php

namespace app\components;

use Yii;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Behavior;

/**
 * Forces users flagged with must_change_password to the change-password page.
 * Attached to the web application in config/web.php.
 */
class PasswordChangeGuard extends Behavior
{
    public array $allowedRoutes = ['account/password', 'site/logout', 'site/error', 'site/login', 'site/logo'];

    public function events(): array
    {
        return [Application::EVENT_BEFORE_ACTION => 'check'];
    }

    public function check(ActionEvent $event): void
    {
        $user = Yii::$app->user;
        if ($user->isGuest || !$user->identity->must_change_password) {
            return;
        }
        $route = $event->action->getUniqueId();
        if (in_array($route, $this->allowedRoutes, true) || str_starts_with($route, 'debug/')) {
            return;
        }
        Yii::$app->session->setFlash('warning', 'You must set a new password before continuing.');
        Yii::$app->response->redirect(['/account/password']);
        $event->isValid = false;
    }
}
