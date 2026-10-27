<?php

namespace app\modules\attack\controllers;

use app\components\Rbac;
use app\modules\attack\components\Catalogue;
use app\modules\attack\components\Coverage;
use app\modules\attack\models\AttackMitigation;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class MitigationController extends Controller
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

    /**
     * All mitigations, ranked by how many of the techniques seen in incidents /
     * mapped to open risks they address, with their adoption state.
     */
    public function actionIndex(): string
    {
        $mitigations = AttackMitigation::find()->where(['is_deprecated' => 0])->orderBy(['external_id' => SORT_ASC])->indexBy('id')->all();
        $pairs = (new Query())->select(['mitigation_id', 'technique_id'])->from('attack_mitigation_technique')->all();
        $observed = Coverage::counts(Coverage::LAYER_INCIDENTS);
        $modelled = Coverage::counts(Coverage::LAYER_RISKS);
        $stats = [];
        foreach ($mitigations as $id => $m) {
            $stats[$id] = ['techniques' => 0, 'observed' => 0, 'modelled' => 0];
        }
        foreach ($pairs as $p) {
            $mid = (int) $p['mitigation_id'];
            $tid = (int) $p['technique_id'];
            if (!isset($stats[$mid])) {
                continue;
            }
            $stats[$mid]['techniques']++;
            $stats[$mid]['observed'] += isset($observed[$tid]) ? 1 : 0;
            $stats[$mid]['modelled'] += isset($modelled[$tid]) ? 1 : 0;
        }
        $adoption = Coverage::adoption();
        uksort($mitigations, static fn($a, $b) => [-$stats[$a]['observed'], -$stats[$a]['modelled'], $mitigations[$a]->external_id]
            <=> [-$stats[$b]['observed'], -$stats[$b]['modelled'], $mitigations[$b]->external_id]);

        return $this->render('index', [
            'mitigations' => $mitigations,
            'stats' => $stats,
            'adoption' => $adoption,
            'release' => Catalogue::release(),
        ]);
    }

    public function actionView(string $id): string
    {
        $model = AttackMitigation::find()->where(['external_id' => strtoupper($id)])->one();
        if (!$model) {
            throw new NotFoundHttpException('ATT&CK mitigation not found.');
        }
        $techniques = $model->getTechniques()->with(['parent', 'tactics'])->all();
        $decisions = Decision::find()->alias('d')
            ->innerJoin(['l' => 'decision_attack_mitigation'], 'l.decision_id = d.id')
            ->where(['l.mitigation_id' => $model->id])
            ->orderBy(['d.archived_at' => SORT_ASC, 'd.decision_date' => SORT_DESC])->all();
        $controls = Control::find()->alias('c')
            ->innerJoin(['l' => 'control_attack_mitigation'], 'l.control_id = c.id')
            ->where(['l.mitigation_id' => $model->id])
            ->orderBy(['c.archived_at' => SORT_ASC, 'c.reference' => SORT_ASC])->all();

        return $this->render('view', [
            'model' => $model,
            'techniques' => $techniques,
            'decisions' => $decisions,
            'controls' => $controls,
            'adoption' => Coverage::adoption()[$model->id] ?? ['state' => Coverage::STATE_GAP, 'controls' => [], 'decisions' => []],
            'observed' => Coverage::counts(Coverage::LAYER_INCIDENTS),
            'modelled' => Coverage::counts(Coverage::LAYER_RISKS),
            'release' => Catalogue::release(),
        ]);
    }
}
