<?php
/**
 * Completed reviews of a risk or decision, most recent first.
 * @var app\models\RecordReview[] $reviews
 */

use yii\helpers\Html;

$fmt = Yii::$app->formatter;
?>
<h2>Review history</h2>
<?php if (!$reviews): ?>
    <p class="null">No reviews recorded.</p>
<?php else: ?>
<table class="list">
    <thead><tr><th>Reviewed on</th><th>Outcome</th><th>Reviewed by</th><th>Next review</th></tr></thead>
    <tbody>
    <?php foreach ($reviews as $r): ?>
        <tr>
            <td>
                <?= $fmt->asDate($r->reviewed_on) ?>
                <?php if ($r->due_on): ?>
                    <div class="muted small">Due <?= $fmt->asDate($r->due_on) ?><?= $r->getWasLate() ? ' (late)' : '' ?></div>
                <?php endif; ?>
            </td>
            <td><?= nl2br(Html::encode($r->outcome)) ?></td>
            <td><?= Html::encode($r->reviewedBy->full_name ?? '—') ?></td>
            <td><?= $r->next_review_date ? $fmt->asDate($r->next_review_date) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
