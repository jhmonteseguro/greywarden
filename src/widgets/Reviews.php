<?php

namespace app\widgets;

use app\components\Rbac;
use app\models\DomainRecord;
use app\models\RecordReview;
use yii\base\Widget;

/**
 * "Reviews" tab content: a risk's or decision's completed reviews, most recent
 * first, plus for analysts on a live record the form to record one.
 * View file: widgets/views/reviews.php
 */
class Reviews extends Widget
{
    public DomainRecord $model;
    /** @var RecordReview[]|null pre-loaded reviews */
    public ?array $reviews = null;

    public function run(): string
    {
        $canEdit = Rbac::canManage() && !$this->model->getIsArchived();
        return $this->render('reviews', [
            'model' => $this->model,
            'reviews' => $this->reviews ?? RecordReview::listFor($this->model),
            'canEdit' => $canEdit,
            'review' => $canEdit ? RecordReview::formModel($this->model) : null,
            'dateAttribute' => RecordReview::dateAttribute($this->model),
        ]);
    }
}
