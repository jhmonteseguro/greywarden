<?php
/**
 * @var yii\web\View $this
 * @var app\models\DomainRecord $model
 */
if (!$model->isArchived) {
    return;
}
?>
<div class="alert alert-secondary gw-callout gw-callout--archived" role="status">
    <i class="bi bi-archive" aria-hidden="true"></i>
    This record was archived on <?= Yii::$app->formatter->asDatetime($model->archived_at) ?>. It is read-only until restored.
</div>
