<?php
/**
 * @var yii\web\View $this
 * @var app\models\AuditLog $entry
 */

use app\helpers\Badge;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use yii\helpers\Html;

$this->title = 'Audit entry #' . $entry->id;
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = ['label' => 'Audit log', 'url' => ['index']];
$this->params['breadcrumbs'][] = '#' . $entry->id;
?>
<div class="row g-4">
    <div class="col-xl-5">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Event</h2>
            <?= DetailView::widget([
                'model' => $entry,
                'attributes' => [
                    'created_at:datetime',
                    ['attribute' => 'action', 'format' => 'raw', 'value' => Badge::auditAction($entry->action, $entry->getActionLabel())],
                    ['attribute' => 'entity_type', 'label' => 'Type', 'value' => $entry->getEntityLabelText()],
                    ['label' => 'Record', 'format' => 'raw', 'value' => ($url = $entry->getEntityUrl()) ? Html::a(Html::encode($entry->entity_label), $url) : Html::encode($entry->entity_label)],
                    ['attribute' => 'username', 'label' => 'User'],
                    ['attribute' => 'ip', 'label' => 'IP address'],
                    ['attribute' => 'user_agent', 'label' => 'User agent'],
                ],
            ]) ?>
        </section>
    </div>
    <div class="col-xl-7">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Changes</h2>
            <?php $rows = $entry->getFormattedChanges(); ?>
            <?php if (!$rows): ?>
                <p class="gw-empty">No field changes recorded for this event.</p>
            <?php else: ?>
                <table class="table table-sm gw-diff">
                    <thead><tr><th>Field</th><th>Old</th><th>New</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <th scope="row" class="gw-diff__field"><?= Html::encode($row['field']) ?></th>
                            <td class="gw-diff__old"><?= $row['old'] === null ? '<span class="gw-null">&mdash;</span>' : nl2br(Html::encode($row['old'])) ?></td>
                            <td class="gw-diff__new"><?= $row['new'] === null ? '<span class="gw-null">&mdash;</span>' : nl2br(Html::encode($row['new'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>
