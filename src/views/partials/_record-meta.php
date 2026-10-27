<?php
/**
 * Created/updated/archived metadata block for view pages.
 * @var yii\web\View $this
 * @var app\models\DomainRecord $model
 */

use yii\helpers\Html;

$fmt = Yii::$app->formatter;
?>
<dl class="gw-dl gw-record-meta">
    <dt>Created</dt>
    <dd><?= $fmt->asDatetime($model->created_at) ?> by <?= Html::encode($model->creator->full_name ?? 'system') ?></dd>
    <dt>Last updated</dt>
    <dd><?= $fmt->asDatetime($model->updated_at) ?> by <?= Html::encode($model->updater->full_name ?? 'system') ?></dd>
    <?php if ($model->isArchived): ?>
        <dt>Archived</dt>
        <dd><?= $fmt->asDatetime($model->archived_at) ?> by <?= Html::encode($model->archiver->full_name ?? 'system') ?></dd>
    <?php endif; ?>
</dl>
