<?php
/**
 * Files attached to the record: listed by name only, the files stay in the register.
 * @var app\models\Attachment[] $attachments
 */

use yii\helpers\Html;

if (!$attachments) {
    return;
}
$fmt = Yii::$app->formatter;
?>
<h2>Supporting files</h2>
<table class="list">
    <thead><tr><th>File</th><th>Added</th></tr></thead>
    <tbody>
    <?php foreach ($attachments as $a): ?>
        <tr>
            <td><?= Html::encode($a->original_name) ?> <span class="muted small">(<?= $fmt->asShortSize($a->size, 0) ?>)</span></td>
            <td><?= $fmt->asDate($a->created_at) ?> by <?= Html::encode($a->creator->full_name ?? 'system') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="muted small">Files are held in the register and are not included in this document.</p>
