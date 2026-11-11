<?php

namespace app\components;

use Yii;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Behavior;

/**
 * When Admin › Settings › Sign-in security requires two-step sign-in, sends signed-in users who
 * have no method yet to Account › Sign-in security until they set one up. Attached to the web
 * application in config/web.php, after PasswordChangeGuard (a forced password change comes first).
 */
class MfaGuard extends Behavior
{
    public array $allowedRoutes = [
        'account/security', 'account/confirm', 'account/totp', 'account/passkey-options', 'account/passkey-add',
        'account/recovery-codes', 'account/password',
        'site/logout', 'site/error', 'site/login', 'site/logo',
    ];

    public function events(): array
    {
        return [Application::EVENT_BEFORE_ACTION => 'check'];
    }

    public function check(ActionEvent $event): void
    {
        $user = Yii::$app->user;
        if ($user->isGuest || !$event->isValid) {
            return;
        }
        $identity = $user->identity;
        if ($identity->must_change_password || !Mfa::isRequiredFor($identity) || Mfa::isEnabled($identity)) {
            return;
        }
        $route = $event->action->getUniqueId();
        if (in_array($route, $this->allowedRoutes, true) || str_starts_with($route, 'debug/')) {
            return;
        }
        Yii::$app->session->setFlash('warning', 'Your administrator requires two-step sign-in. Set up an authenticator app or a passkey to continue.');
        Yii::$app->response->redirect(['/account/security']);
        $event->isValid = false;
    }
}
