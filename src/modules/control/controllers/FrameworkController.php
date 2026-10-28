<?php

namespace app\modules\control\controllers;

use app\components\Rbac;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\components\FrameworkCoverage;
use app\modules\control\models\FrameworkRequirement;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * ISO 27001 Statement of Applicability, Cyber Essentials and SOC 2 readiness,
 * rolled up from the Control Register. Frameworks switched off in Admin ›
 * Settings (and SOC 2 categories out of scope) are not found.
 */
class FrameworkController extends Controller
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
        $summaries = [];
        foreach (FrameworkCatalogue::enabled() as $framework) {
            $summaries[$framework] = FrameworkCoverage::summary(FrameworkCoverage::rows($framework));
        }
        return $this->render('index', ['summaries' => $summaries]);
    }

    public function actionView(string $framework, string $state = ''): string
    {
        $key = FrameworkCatalogue::fromSlug($framework);
        if ($key === null || !FrameworkCatalogue::isEnabled($key)) {
            throw new NotFoundHttpException('Framework not found.');
        }
        $rows = FrameworkCoverage::rows($key);
        $summary = FrameworkCoverage::summary($rows);
        $state = array_key_exists($state, FrameworkCoverage::stateLabels()) ? $state : '';
        if ($state !== '') {
            $rows = array_filter($rows, static fn($r) => $r['state'] === $state);
        }
        return $this->render('view', [
            'framework' => $key,
            'rows' => $rows,
            'summary' => $summary,
            'state' => $state,
            'devices' => $key === FrameworkCatalogue::CYBER_ESSENTIALS ? $this->deviceReadiness() : null,
            'soc2Scope' => $key === FrameworkCatalogue::SOC2 ? FrameworkCatalogue::soc2Scope() : null,
        ]);
    }

    public function actionRequirement(int $id): Response|string
    {
        $model = FrameworkRequirement::findOne($id);
        if (!$model || !$model->getIsInScope()) {
            throw new NotFoundHttpException('Requirement not found.');
        }
        if (Yii::$app->request->isPost) {
            if (!Rbac::canManage()) {
                throw new ForbiddenHttpException('You are not allowed to change the Statement of Applicability.');
            }
            if ($model->load(Yii::$app->request->post()) && $model->save()) {
                Yii::$app->session->setFlash('success', sprintf('%s saved.', $model->external_id));
                return $this->redirect($model->getViewUrl());
            }
        }
        $row = FrameworkCoverage::rows($model->framework, [$model->id])[$model->id];
        return $this->render('requirement', ['model' => $model, 'row' => $row, 'controls' => $model->getControls()->all()]);
    }

    /**
     * In-service devices against the device checks mapped to a Cyber Essentials requirement.
     * @return array{total:int, checks:array<int,array{check:\app\modules\inventory\components\DeviceCheck, pass:int, fail:int, unknown:int, requirements:string[]}>}
     */
    private function deviceReadiness(): array
    {
        $requirements = (new \yii\db\Query())->select(['l.control_id', 'r.external_id'])->from(['l' => 'control_framework_requirement'])
            ->innerJoin(['r' => 'framework_requirement'], 'r.id = l.requirement_id')
            ->where(['r.framework' => FrameworkCatalogue::CYBER_ESSENTIALS, 'l.control_id' => array_keys(DeviceChecks::all()) ?: [0]])
            ->orderBy('r.sort_order')->all();
        $byControl = [];
        foreach ($requirements as $r) {
            $byControl[(int) $r['control_id']][] = $r['external_id'];
        }
        $checks = [];
        foreach (DeviceChecks::all() as $id => $check) {
            if (!isset($byControl[$id])) {
                continue;
            }
            $row = ['check' => $check, 'requirements' => $byControl[$id]];
            foreach ([DevicePosture::RESULT_PASS => 'pass', DevicePosture::RESULT_FAIL => 'fail', DevicePosture::RESULT_UNKNOWN => 'unknown'] as $result => $k) {
                $row[$k] = (int) Device::find()->active()->inService()->checkResult($check, $result)->count();
            }
            $checks[$id] = $row;
        }
        return ['total' => (int) Device::find()->active()->inService()->count(), 'checks' => $checks];
    }
}
