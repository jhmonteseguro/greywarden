<?php

namespace app\widgets;

use app\components\Rbac;
use app\models\Attachment;
use app\models\DomainRecord;
use yii\base\Widget;

/**
 * "Files" tab content: a record's attachments plus, for analysts on a live
 * record, the upload form. View file: widgets/views/attachments.php
 */
class Attachments extends Widget
{
    public DomainRecord $model;
    /** @var Attachment[]|null pre-loaded attachments */
    public ?array $attachments = null;
    public string $intro = 'Screenshots, reports and documents that evidence this record.';

    public function run(): string
    {
        return $this->render('attachments', [
            'model' => $this->model,
            'attachments' => $this->attachments ?? Attachment::listFor($this->model),
            'canEdit' => Rbac::canManage() && !$this->model->getIsArchived(),
            'intro' => $this->intro,
        ]);
    }
}
