<?php
/**
 * Recent activity as an event stream: time | type | action | record | fields | user.
 *
 * @var yii\web\View $this
 * @var app\models\AuditLog[] $entries
 */

use app\helpers\Badge;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
?>
<?php if (!$entries): ?>
    <div class="gw-empty-state">
        <i class="bi bi-activity" aria-hidden="true"></i>
        <p>No activity yet.</p>
    </div>
<?php else: ?>
<ul class="gw-activity">
    <?php foreach ($entries as $e):
        $changes = array_keys($e->getChangesArray());
        $fields = $e->action === 'update' ? array_slice(array_map(static fn($r) => $r['field'], $e->getFormattedChanges()), 0, 4) : [];
        // "RSK-0001 · Title" -> mono reference + plain title
        $parts = explode(' · ', (string) $e->entity_label, 2);
        $label = Html::tag('span', Html::encode($parts[0]), ['class' => 'gw-activity__ref'])
            . (isset($parts[1]) ? ' <span class="gw-activity__title">' . Html::encode($parts[1]) . '</span>' : '');
    ?>
        <li class="gw-activity__item gw-activity__item--<?= Html::encode($e->action) ?>">
            <time class="gw-activity__time" datetime="<?= Html::encode($e->created_at) ?>" title="<?= Html::encode($fmt->asDatetime($e->created_at)) ?>"><?= $fmt->asRelativeTime($e->created_at) ?></time>
            <span class="gw-activity__type"><?= Html::encode($e->getEntityLabelText()) ?></span>
            <span class="gw-activity__action"><?= Badge::auditAction($e->action, $e->getActionLabel()) ?></span>
            <span class="gw-activity__target">
                <?= ($url = $e->getEntityUrl()) ? Html::a($label, $url, ['class' => 'gw-activity__link']) : $label ?>
                <?php if ($fields): ?>
                    <span class="gw-activity__fields"><?= Html::encode(implode(', ', $fields)) ?><?= count($changes) > 4 ? '…' : '' ?></span>
                <?php endif; ?>
            </span>
            <span class="gw-activity__who"><i class="bi bi-person" aria-hidden="true"></i> <?= Html::encode($e->username ?? 'system') ?></span>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
