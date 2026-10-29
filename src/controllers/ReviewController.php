<?php

namespace app\controllers;

use app\components\Rbac;
use app\models\RecordReview;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Completed reviews of risks and decisions (see RecordReview::ENTITY_TYPES).
 *
 * Access: record -> records.manage while the record is not archived; remove ->
 * the reviewer or whoever recorded it, or an administrator.
 */
class ReviewController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['record', 'remove'], 'roles' => [Rbac::PERM_MANAGE]],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['record' => ['POST'], 'remove' => ['POST']],
            ],
        ];
    }

    public function actionRecord(string $entity, int $id): Response
    {
        $owner = RecordReview::findOwner($entity, $id) ?? throw new NotFoundHttpException('Record not found.');
        $back = $owner->getViewUrl() + ['#' => 'tab-reviews'];
        if ($owner->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only. Restore the record to record a review.');
            return $this->redirect($back);
        }
        $data = (array) Yii::$app->request->post('RecordReview', []);
        $review = RecordReview::record($owner, $data);
        if ($review->hasErrors()) {
            Yii::$app->session->setFlash('danger', 'The review was not recorded: ' . implode(' ', $review->getErrorSummary(false)));
            // Give the form back as it was sent, with its errors
            Yii::$app->session->set(RecordReview::draftKey($owner), ['data' => array_map(
                static fn($v) => is_scalar($v) ? mb_substr((string) $v, 0, RecordReview::MAX_LENGTH * 2) : null, $data), 'errors' => $review->getErrors()]);
            return $this->redirect($back);
        }
        $fmt = Yii::$app->formatter;
        Yii::$app->session->setFlash('success', sprintf('Review of %s recorded.%s', $owner->getReferenceValue(),
            $review->next_review_date ? ' Next review ' . $fmt->asDate($review->next_review_date) . '.' : ''));
        return $this->redirect($back);
    }

    public function actionRemove(int $id): Response
    {
        $review = RecordReview::findOne($id) ?? throw new NotFoundHttpException('Review not found.');
        if (!$review->canRemove()) {
            throw new ForbiddenHttpException('Only the reviewer or an administrator can remove a review.');
        }
        $owner = $review->getOwner();
        $back = $owner ? $owner->getViewUrl() + ['#' => 'tab-reviews'] : ['/dashboard/default/index'];
        if ($owner?->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only.');
            return $this->redirect($back);
        }
        $review->remove();
        Yii::$app->session->setFlash('info', 'Review removed. The record\'s review date is unchanged and its history keeps the review.');
        return $this->redirect($back);
    }
}
