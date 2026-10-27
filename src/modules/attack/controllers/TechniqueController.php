<?php

namespace app\modules\attack\controllers;

use app\components\Rbac;
use app\modules\attack\components\Catalogue;
use app\modules\attack\components\Coverage;
use app\modules\attack\models\AttackTechnique;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use Yii;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class TechniqueController extends Controller
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

    /** The ATT&CK matrix, shaded by incidents (observed) or open risks (modelled). */
    public function actionIndex(string $layer = Coverage::LAYER_INCIDENTS, string $show = 'all'): string
    {
        $layer = array_key_exists($layer, Coverage::layerLabels()) ? $layer : Coverage::LAYER_INCIDENTS;
        $show = $show === 'mapped' ? 'mapped' : 'all';

        $tactics = Catalogue::tactics();
        $techniques = AttackTechnique::find()->where(['is_deprecated' => 0, 'is_subtechnique' => 0])->orderBy(['name' => SORT_ASC])->indexBy('id')->all();
        $subCounts = array_map('intval', (new Query())->select(['n' => 'COUNT(*)', 'parent_id'])->from('attack_technique')
            ->where(['is_deprecated' => 0, 'is_subtechnique' => 1])->groupBy('parent_id')->indexBy('parent_id')->column());
        $membership = (new Query())->select(['technique_id', 'tactic_id'])->from('attack_technique_tactic')
            ->where(['technique_id' => array_keys($techniques)])->all();

        $counts = Coverage::counts($layer, true);
        $columns = [];
        foreach ($membership as $row) {
            $tid = (int) $row['technique_id'];
            if ($show === 'mapped' && empty($counts[$tid])) {
                continue;
            }
            $columns[(int) $row['tactic_id']][] = $techniques[$tid];
        }
        foreach ($columns as &$list) {
            usort($list, static fn($a, $b) => strcasecmp($a->name, $b->name));
        }
        unset($list);

        return $this->render('index', [
            'layer' => $layer,
            'show' => $show,
            'tactics' => $tactics,
            'columns' => $columns,
            'counts' => $counts,
            'subCounts' => $subCounts,
            'release' => Catalogue::release(),
        ]);
    }

    public function actionView(string $id): string
    {
        $model = AttackTechnique::find()->with(['parent', 'tactics', 'subtechniques', 'mitigations'])->where(['external_id' => strtoupper($id)])->one();
        if (!$model) {
            throw new NotFoundHttpException('ATT&CK technique not found.');
        }
        // A parent technique page includes everything mapped to its sub-techniques
        $ids = array_merge([$model->id], array_map(static fn($s) => $s->id, $model->subtechniques));

        $incidents = Incident::find()->alias('i')->with(['techniques'])
            ->innerJoin(['l' => 'incident_attack_technique'], 'l.incident_id = i.id')
            ->where(['l.technique_id' => $ids])->andWhere(['i.archived_at' => null])
            ->distinct()->orderBy(['i.detected_at' => SORT_DESC])->all();
        $risks = Risk::find()->alias('r')->with(['techniques'])
            ->innerJoin(['l' => 'risk_attack_technique'], 'l.risk_id = r.id')
            ->where(['l.technique_id' => $ids])->andWhere(['r.archived_at' => null])
            ->distinct()->orderBy(['r.inherent_score' => SORT_DESC])->all();
        $devices = $incidents ? Device::find()->alias('d')
            ->innerJoin(['l' => 'incident_device'], 'l.device_id = d.id')
            ->where(['l.incident_id' => array_map(static fn($i) => $i->id, $incidents)])->andWhere(['d.archived_at' => null])
            ->distinct()->orderBy(['d.asset_tag' => SORT_ASC])->all() : [];

        return $this->render('view', [
            'model' => $model,
            'ids' => $ids,
            'incidents' => $incidents,
            'risks' => $risks,
            'devices' => $devices,
            'release' => Catalogue::release(),
        ]);
    }
}
