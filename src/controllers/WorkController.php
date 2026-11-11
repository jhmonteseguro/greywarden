<?php

namespace app\controllers;

use app\components\MyWork;
use app\components\Rbac;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * My work: what the signed-in user has to do (components/MyWork).
 */
class WorkController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => [Rbac::PERM_VIEW]]],
            ],
        ];
    }

    public function actionIndex(): string
    {
        /** @var \app\models\User $user */
        $user = Yii::$app->user->identity;
        $groups = MyWork::groups($user);
        return $this->render('index', [
            'groups' => $groups,
            'later' => MyWork::laterActions($user),
            'urgent' => MyWork::urgentCount($groups),
        ]);
    }
}
