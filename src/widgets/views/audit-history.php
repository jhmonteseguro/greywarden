<?php
/**
 * @var yii\web\View $this
 * @var app\models\AuditLog[] $entries
 * @var bool $showEntity
 */

use app\helpers\Badge;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
?>
<?php if (!$entries): ?>
    <p class="gw-empty">No history recorded yet.</p>
<?php else: ?>
<ol class="gw-history">
    <?php foreach ($entries as $entry): ?>
        <li class="gw-history__entry" data-action="<?= Html::encode($entry->action) ?>">
            <div class="gw-history__meta">
                <?= Badge::auditAction($entry->action, $entry->getActionLabel()) ?>
                <?php if ($showEntity && $entry->entity_label): ?>
                    <span class="gw-history__entity">
                        <?= ($url = $entry->getEntityUrl()) ? Html::a(Html::encode($entry->entity_label), $url, ['class' => 'gw-ref']) : Html::encode($entry->entity_label) ?>
                    </span>
                <?php endif; ?>
                <span class="gw-history__user"><i class="bi bi-person" aria-hidden="true"></i> <?= Html::encode($entry->username ?? 'system') ?></span>
                <time class="gw-history__time" datetime="<?= Html::encode($entry->created_at) ?>"><?= $fmt->asDatetime($entry->created_at) ?></time>
                <?php if ($entry->ip): ?><span class="gw-history__ip"><?= Html::encode($entry->ip) ?></span><?php endif; ?>
            </div>
            <?php $rows = $entry->getFormattedChanges(); ?>
            <?php if ($rows && $entry->action !== 'delete'): ?>
                <table class="table table-sm gw-diff">
                    <thead><tr><th scope="col">Field</th><th scope="col">Old</th><th scope="col">New</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <th scope="row" class="gw-diff__field"><?= Html::encode($row['field']) ?></th>
                            <td class="gw-diff__old"><?= $row['old'] === null ? '<span class="gw-null">&mdash;</span>' : nl2br(Html::encode(mb_strimwidth($row['old'], 0, 600, '…'))) ?></td>
                            <td class="gw-diff__new"><?= $row['new'] === null ? '<span class="gw-null">&mdash;</span>' : nl2br(Html::encode(mb_strimwidth($row['new'], 0, 600, '…'))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($rows && $entry->action === 'delete'): ?>
                <p class="gw-history__note">Record deleted (<?= count($rows) ?> fields captured in the audit log).</p>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>
<?php endif; ?>
