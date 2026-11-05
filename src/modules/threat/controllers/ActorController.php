<?php

namespace app\modules\threat\controllers;

use app\components\Rbac;
use app\modules\attack\components\Catalogue;
use app\modules\attack\models\AttackGroup;
use app\modules\threat\components\ActorProfile;
use app\modules\threat\models\ActorSearch;
use app\modules\threat\models\ThreatTriage;
use Yii;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Threat actors: the groups MITRE ATT&CK tracks, what this organisation's news says about
 * them and how its defences stand against what they do.
 *
 *   /threat/actors           every group, the most recently named first
 *   /threat/actors/G0016     one group
 */
class ActorController extends Controller
{
    public const STORIES = 20;

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
        $searchModel = new ActorSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'counts' => ActorSearch::counts(),
            'summaries' => ActorProfile::summaries(array_map(static fn(AttackGroup $g) => $g->id, $dataProvider->getModels())),
            'release' => Catalogue::release(),
        ]);
    }

    public function actionView(string $id): string
    {
        $model = AttackGroup::findByExternalId($id);
        if ($model === null) {
            throw new NotFoundHttpException('Threat actor not found.');
        }
        $stories = ThreatTriage::find()->forOrganisation()->joinWith('story')
            ->andWhere(['threat_triage.story_id' => (new Query())->select('a.story_id')->from(['a' => 'threat_article'])
                ->innerJoin(['x' => 'threat_article_actor'], 'x.article_id = a.id')->where(['x.group_id' => $model->id])]);
        return $this->render('view', [
            'model' => $model,
            'profile' => new ActorProfile($model),
            'storyCount' => (int) (clone $stories)->count(),
            'stories' => $stories->orderBy(['threat_story.last_seen_at' => SORT_DESC])->limit(self::STORIES)->all(),
            'release' => Catalogue::release(),
        ]);
    }
}
