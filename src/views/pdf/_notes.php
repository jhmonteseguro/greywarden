<?php
/**
 * Dated notes on the record, oldest first so they read as a narrative.
 * @var app\models\RecordNote[] $notes
 */

use yii\helpers\Html;

if (!$notes) {
    return;
}
$fmt = Yii::$app->formatter;
?>
<h2>Notes</h2>
<table class="list timeline">
    <thead><tr><th>When</th><th>Note</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($notes as $n): ?>
        <tr>
            <td class="timeline__when"><?= $fmt->asDatetime($n->created_at) ?></td>
            <td>
                <?= nl2br(Html::encode($n->body)) ?>
                <?php if ($name = $n->getFileName()): ?>
                    <div class="muted small">File: <?= Html::encode($name) ?><?= $n->getIsFileRemoved() ? ' (since removed)' : '' ?></div>
                <?php endif; ?>
            </td>
            <td class="timeline__by"><?= Html::encode($n->creator->full_name ?? 'system') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
