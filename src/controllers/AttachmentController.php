<?php

namespace app\controllers;

use app\components\Rbac;
use app\models\Attachment;
use app\models\DomainRecord;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Files attached to risks, controls, decisions, incidents, cloud accounts and cloud assets.
 *
 * Access: download -> records.view; upload / remove -> records.manage, and
 * only while the owning record is not archived. Files are only ever served
 * through download, never from the web root.
 */
class AttachmentController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['download'], 'roles' => [Rbac::PERM_VIEW]],
                    ['allow' => true, 'actions' => ['upload', 'remove'], 'roles' => [Rbac::PERM_MANAGE]],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['upload' => ['POST'], 'remove' => ['POST']],
            ],
        ];
    }

    public function actionUpload(string $entity, int $id): Response
    {
        $owner = $this->findOwner($entity, $id);
        $back = $owner->getViewUrl() + ['#' => 'tab-files'];
        if ($owner->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only. Restore the record to attach files.');
            return $this->redirect($back);
        }
        $files = UploadedFile::getInstancesByName('files');
        if (!$files) {
            // PHP drops the whole POST body when it exceeds post_max_size
            Yii::$app->session->setFlash('danger', sprintf('Nothing was received. Choose a file of at most %d MB.', Yii::$app->params['attachments.maxSizeMb']));
            return $this->redirect($back);
        }
        $description = Yii::$app->request->post('description');
        $added = [];
        $errors = [];
        foreach ($files as $file) {
            $result = Attachment::attach($owner, $file, $description);
            $result instanceof Attachment ? $added[] = $result->original_name : $errors[] = $result;
        }
        if ($added) {
            Yii::$app->session->setFlash('success', sprintf('Attached to %s: %s.', $owner->getReferenceValue(), implode(', ', $added)));
        }
        if ($errors) {
            Yii::$app->session->setFlash('danger', implode(' ', $errors));
        }
        return $this->redirect($back);
    }

    public function actionDownload(int $id, bool $inline = false): Response
    {
        $attachment = $this->findAttachment($id);
        if (!is_file($attachment->getPath())) {
            Yii::error("Attachment #$id is missing from disk: " . $attachment->getPath(), __METHOD__);
            throw new NotFoundHttpException('The file is missing from storage. Restore it from a backup.');
        }
        $response = Yii::$app->response->sendFile($attachment->getPath(), $attachment->original_name, [
            'mimeType' => $attachment->mime_type,
            'inline' => $inline && $attachment->getIsPreviewable(),
        ]);
        // Uploaded content never runs as a page, even if a browser second-guesses the type
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    public function actionRemove(int $id): Response
    {
        $attachment = $this->findAttachment($id);
        $owner = $attachment->getOwner();
        $back = $owner ? $owner->getViewUrl() + ['#' => 'tab-files'] : ['/dashboard/default/index'];
        if ($owner?->getIsArchived()) {
            Yii::$app->session->setFlash('warning', 'Archived records are read-only.');
            return $this->redirect($back);
        }
        $attachment->remove();
        Yii::$app->session->setFlash('info', sprintf('Removed %s.', $attachment->original_name));
        return $this->redirect($back);
    }

    private function findOwner(string $entity, int $id): DomainRecord
    {
        $owner = Attachment::findOwner($entity, $id);
        if ($owner === null) {
            throw new NotFoundHttpException('Record not found.');
        }
        return $owner;
    }

    private function findAttachment(int $id): Attachment
    {
        $attachment = Attachment::findOne($id);
        if ($attachment === null) {
            throw new NotFoundHttpException('File not found.');
        }
        return $attachment;
    }
}
