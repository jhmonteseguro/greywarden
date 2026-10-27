<?php
/**
 * @var yii\web\View $this
 * @var app\models\DomainRecord $model
 * @var app\models\Attachment[] $attachments
 * @var bool $canEdit
 * @var string $intro
 */

use app\models\Attachment;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
?>
<div class="row g-4">
    <div class="col-xl-<?= $canEdit ? 8 : 12 ?>">
        <section class="gw-panel gw-panel--flush">
            <h2 class="gw-panel__title">Files <span class="gw-panel__count"><?= count($attachments) ?></span></h2>
            <?php if (!$attachments): ?>
                <p class="gw-empty">No files attached yet. <?= Html::encode($intro) ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table gw-coverage__table gw-attachments">
                        <thead><tr><th scope="col">File</th><th scope="col">Size</th><th scope="col">Added</th><?= $canEdit ? '<th scope="col"><span class="visually-hidden">Actions</span></th>' : '' ?></tr></thead>
                        <tbody>
                        <?php foreach ($attachments as $a): ?>
                            <tr>
                                <td>
                                    <div class="gw-attachments__name">
                                        <i class="bi bi-<?= $a->getIcon() ?>" aria-hidden="true"></i>
                                        <?= Html::a(Html::encode($a->original_name), ['/attachment/download', 'id' => $a->id], ['title' => 'Download']) ?>
                                        <?php if ($a->getIsPreviewable()): ?>
                                            <?= Html::a('<i class="bi bi-eye" aria-hidden="true"></i><span class="visually-hidden">Preview ' . Html::encode($a->original_name) . '</span>',
                                                ['/attachment/download', 'id' => $a->id, 'inline' => 1],
                                                ['class' => 'gw-icon-btn', 'title' => 'Preview', 'target' => '_blank', 'rel' => 'noopener']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($a->description): ?><div class="gw-attachments__desc"><?= Html::encode($a->description) ?></div><?php endif; ?>
                                    <?php if ($a->controlTest): ?>
                                        <div class="gw-muted gw-small"><i class="bi bi-clipboard-check" aria-hidden="true"></i>
                                            Evidence of the test on <?= $fmt->asDate($a->controlTest->tested_on) ?></div>
                                    <?php endif; ?>
                                    <div class="gw-muted gw-small gw-mono" title="SHA-256 <?= Html::encode($a->sha256) ?>">SHA-256 <?= Html::encode(substr($a->sha256, 0, 16)) ?>…</div>
                                </td>
                                <td class="gw-mono text-nowrap"><?= $fmt->asShortSize($a->size, 1) ?></td>
                                <td class="text-nowrap">
                                    <?= $fmt->asDate($a->created_at) ?>
                                    <div class="gw-muted gw-small"><?= Html::encode($a->creator->full_name ?? '—') ?></div>
                                </td>
                                <?php if ($canEdit): ?>
                                    <td class="text-end"><?= Html::a('<i class="bi bi-trash" aria-hidden="true"></i>', ['/attachment/remove', 'id' => $a->id], [
                                        'class' => 'gw-icon-btn', 'title' => 'Remove this file', 'aria-label' => 'Remove ' . $a->original_name,
                                        'data-method' => 'post', 'data-confirm' => sprintf('Remove "%s"? The file is deleted; the record\'s history keeps its name and checksum.', $a->original_name),
                                    ]) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php if ($canEdit): ?>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-paperclip" aria-hidden="true"></i> Attach files</h2>
            <?= Html::beginForm(['/attachment/upload', 'entity' => $model::entityType(), 'id' => $model->id], 'post', ['enctype' => 'multipart/form-data', 'id' => 'attachment-form']) ?>
                <div class="mb-3 gw-field">
                    <label class="form-label gw-field__label" for="attachment-files">Files</label>
                    <input type="file" class="form-control" id="attachment-files" name="files[]" multiple required accept="<?= Html::encode(Attachment::acceptAttribute()) ?>" aria-describedby="attachment-files-hint">
                    <div class="form-text gw-field__hint" id="attachment-files-hint"><?= Html::encode(Attachment::extensionList()) ?>; up to <?= (int) Yii::$app->params['attachments.maxSizeMb'] ?> MB per upload.</div>
                </div>
                <div class="mb-3 gw-field">
                    <label class="form-label gw-field__label" for="attachment-description">Description</label>
                    <input type="text" class="form-control" id="attachment-description" name="description" maxlength="255" aria-describedby="attachment-description-hint">
                    <div class="form-text gw-field__hint" id="attachment-description-hint">Optional, e.g. "Intune MFA report, October".</div>
                </div>
                <?= Html::submitButton('Attach', ['class' => 'btn btn-primary gw-btn']) ?>
            <?= Html::endForm() ?>
        </section>
    </div>
    <?php endif; ?>
</div>
