<?php
/**
 * @var yii\web\View $this
 * @var app\models\DomainRecord $model
 * @var app\models\RecordNote[] $notes
 * @var app\models\Attachment[] $attachments
 * @var bool $canEdit
 * @var string $draft text of a refused note, offered again
 */

use app\models\Attachment;
use app\models\RecordNote;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
?>
<div class="row g-4">
    <div class="col-xl-<?= $canEdit ? 8 : 12 ?>">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Notes <span class="gw-panel__count"><?= count($notes) ?></span></h2>
            <?php if (!$notes): ?>
                <p class="gw-empty">No notes yet. Use notes to log progress, e.g. "Emailed DevOps requesting the change, copy saved in Files".</p>
            <?php else: ?>
                <ol class="gw-timeline gw-notes">
                    <?php foreach ($notes as $n): ?>
                        <li class="gw-timeline__entry gw-timeline__entry--note">
                            <div class="gw-timeline__marker" aria-hidden="true"><i class="bi bi-sticky"></i></div>
                            <div class="gw-timeline__body">
                                <div class="gw-timeline__meta">
                                    <time class="gw-timeline__time" datetime="<?= Html::encode($n->created_at) ?>"><?= $fmt->asDatetime($n->created_at) ?></time>
                                    <span class="gw-timeline__author"><i class="bi bi-person" aria-hidden="true"></i> <?= Html::encode($n->creator->full_name ?? 'system') ?></span>
                                    <?php if ($canEdit && $n->canRemove()): ?>
                                        <?= Html::a('<i class="bi bi-trash" aria-hidden="true"></i>', ['/note/remove', 'id' => $n->id], [
                                            'class' => 'gw-icon-btn gw-notes__remove', 'title' => 'Remove this note', 'aria-label' => 'Remove this note',
                                            'data-method' => 'post', 'data-confirm' => 'Remove this note? The record\'s history keeps its text.',
                                        ]) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="gw-timeline__text"><?= nl2br(Html::encode($n->body)) ?></div>
                                <?php if ($name = $n->getFileName()): ?>
                                    <div class="gw-notes__file gw-small">
                                        <?php if ($n->attachment): ?>
                                            <i class="bi bi-<?= $n->attachment->getIcon() ?> gw-muted" aria-hidden="true"></i>
                                            <?= Html::a(Html::encode($name), ['/attachment/download', 'id' => $n->attachment->id], ['title' => 'Download']) ?>
                                        <?php else: ?>
                                            <i class="bi bi-file-earmark-x gw-muted" aria-hidden="true"></i>
                                            <span class="gw-muted"><?= Html::encode($name) ?> (file since removed)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </div>
    <?php if ($canEdit): ?>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-sticky" aria-hidden="true"></i> Add a note</h2>
            <?= Html::beginForm(['/note/add', 'entity' => $model::entityType(), 'id' => $model->id], 'post', ['enctype' => 'multipart/form-data', 'id' => 'note-form']) ?>
                <div class="mb-3 gw-field">
                    <label class="form-label gw-field__label" for="note-body">Note</label>
                    <textarea class="form-control" id="note-body" name="body" rows="4" required maxlength="<?= RecordNote::MAX_LENGTH ?>" aria-describedby="note-body-hint"
                        placeholder="e.g. Emailed DevOps requesting the firewall change"><?= Html::encode($draft) ?></textarea>
                    <div class="form-text gw-field__hint" id="note-body-hint">Up to <?= RecordNote::MAX_LENGTH ?> characters. Dated and signed with your name; notes cannot be edited and appear in the PDF.</div>
                </div>
                <?php if ($attachments): ?>
                <div class="mb-3 gw-field">
                    <label class="form-label gw-field__label" for="note-attachment">Link a file on this record</label>
                    <?= Html::dropDownList('attachment_id', null, ArrayHelper::map($attachments, 'id', 'original_name'),
                        ['class' => 'form-select', 'id' => 'note-attachment', 'prompt' => '— None —']) ?>
                </div>
                <?php endif; ?>
                <div class="mb-3 gw-field">
                    <label class="form-label gw-field__label" for="note-file"><?= $attachments ? 'Or attach a new file' : 'Attach a file' ?></label>
                    <input type="file" class="form-control" id="note-file" name="file" accept="<?= Html::encode(Attachment::acceptAttribute()) ?>" aria-describedby="note-file-hint">
                    <div class="form-text gw-field__hint" id="note-file-hint">Optional. <?= Html::encode(Attachment::extensionList()) ?>; up to <?= (int) Yii::$app->params['attachments.maxSizeMb'] ?> MB. It also appears on the Files tab.</div>
                </div>
                <?= Html::submitButton('Add note', ['class' => 'btn btn-primary gw-btn']) ?>
            <?= Html::endForm() ?>
        </section>
    </div>
    <?php endif; ?>
</div>
