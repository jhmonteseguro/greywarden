<?php

namespace app\widgets;

use app\components\Rbac;
use app\models\Attachment;
use app\models\DomainRecord;
use app\models\RecordNote;
use yii\base\Widget;

/**
 * "Notes" tab content: a record's dated notes, newest first, plus for analysts
 * on a live record the form to add one. View file: widgets/views/notes.php
 */
class Notes extends Widget
{
    public DomainRecord $model;
    /** @var RecordNote[]|null pre-loaded notes */
    public ?array $notes = null;
    /** @var Attachment[]|null pre-loaded attachments, offered as files to link */
    public ?array $attachments = null;

    public function run(): string
    {
        $canEdit = Rbac::canManage() && !$this->model->getIsArchived();
        return $this->render('notes', [
            'model' => $this->model,
            'notes' => $this->notes ?? RecordNote::listFor($this->model),
            'attachments' => $this->attachments ?? Attachment::listFor($this->model),
            'canEdit' => $canEdit,
            'draft' => $canEdit ? RecordNote::takeDraft($this->model) : '',
        ]);
    }
}
