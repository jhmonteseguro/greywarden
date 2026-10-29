<?php

namespace app\controllers;

use app\components\Rbac;
use app\models\Attachment;
use app\models\DomainRecord;
use app\models\RecordNote;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Dated notes on records (controls for now, see RecordNote::ENTITY_TYPES).
 *
 * Access: add -> records.manage while the record is not archived; remove ->
 * the note's author, or an administrator. A note can link a file already on
 * the record, or attach a new one in the same step.
 */
class NoteController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['add', 'remove'], 'roles' => [Rbac::PERM_MANAGE]],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['add' => ['POST'], 'remove' => ['POST']],
            ],
        ];
    }

    public function actionAdd(string $entity, int $id): Response
    {
        $owner = RecordNote::findOwner($entity, $id) ?? throw new NotFoundHttpException('Record not found.');
        $back = $owner->getViewUrl() + ['#' => 'tab-notes'];
        if ($owner->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only. Restore the record to add notes.');
            return $this->redirect($back);
        }
        $request = Yii::$app->request;
        $body = (string) $request->post('body');
        $upload = UploadedFile::getInstanceByName('file');
        if ($upload?->error === UPLOAD_ERR_NO_FILE) {
            $upload = null;
        }

        // Check everything before storing a file, so a refused note leaves nothing behind
        $problem = RecordNote::check($body);
        $file = null;
        if ($problem === null && ($fileId = (int) $request->post('attachment_id'))) {
            $file = Attachment::findOne(['id' => $fileId, 'entity_type' => $owner::entityType(), 'entity_id' => $owner->id]);
            $problem = $file === null ? 'That file is not attached to ' . $owner->getReferenceValue() . '.' : null;
        }
        if ($problem === null && $upload !== null) {
            if ($file !== null) {
                $problem = 'Link an existing file or attach a new one, not both.';
            } else {
                $file = Attachment::attach($owner, $upload);
                $problem = is_string($file) ? $file : null;
            }
        }
        $note = $problem === null ? RecordNote::add($owner, $body, $file) : $problem;

        if ($note instanceof RecordNote) {
            Yii::$app->session->setFlash('success', 'Note added to ' . $owner->getReferenceValue() . ($upload ? ', with ' . $note->getFileName() . ' attached.' : '.'));
        } else {
            Yii::$app->session->setFlash('danger', $note);
            // Give the text back so it need not be typed again
            Yii::$app->session->set(RecordNote::draftKey($owner), mb_substr($body, 0, RecordNote::MAX_LENGTH * 2));
        }
        return $this->redirect($back);
    }

    public function actionRemove(int $id): Response
    {
        $note = RecordNote::findOne($id) ?? throw new NotFoundHttpException('Note not found.');
        if (!$note->canRemove()) {
            throw new ForbiddenHttpException('Only the note\'s author or an administrator can remove it.');
        }
        $owner = $note->getOwner();
        $back = $owner ? $owner->getViewUrl() + ['#' => 'tab-notes'] : ['/dashboard/default/index'];
        if ($owner?->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only.');
            return $this->redirect($back);
        }
        $note->remove();
        Yii::$app->session->setFlash('info', 'Note removed. The record\'s history keeps its text.');
        return $this->redirect($back);
    }
}
