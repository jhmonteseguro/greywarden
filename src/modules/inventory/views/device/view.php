<?php
/**
 * @var yii\web\View $this
 * @var app\modules\inventory\models\Device $model
 */

use app\helpers\Badge;
use app\modules\attack\components\DeviceAttack;
use app\modules\attack\models\AttackTechnique;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\incident\models\Incident;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\components\OsLifecycle;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use app\widgets\LinkedRecords;
use app\widgets\RecordActions;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Device Inventory', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->asset_tag;
$posture = $model->getPosture();
$flags = [];
if ($model->isWarrantyExpiringSoon()) {
    $flags[] = Badge::flag('expiring', 'Warranty expiring');
} elseif ($model->isWarrantyExpired()) {
    $flags[] = Badge::flag('expired', 'Warranty expired');
}
if ($model->isPastEol()) {
    $flags[] = Badge::flag('eol', 'Past end-of-life');
}
$osSupport = $model->getOsSupport();
$flags[] = Badge::osSupport($osSupport);
$flags[] = Badge::lastSeen($model);
$flags = array_filter($flags);
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->asset_tag) . '</span> '
    . Badge::status('device-status', $model->status, Device::statusOptions()) . ' '
    . ($model->isByod() ? Badge::render('ownership', $model->ownership, 'BYOD', ['title' => 'Personally owned device']) . ' ' : '')
    . Badge::posture($posture['state']) . ' ' . implode(' ', $flags);
$this->params['actions'] = RecordActions::widget(['model' => $model]);
$fmt = Yii::$app->formatter;

$risks = $model->risks;
$incidents = $model->incidents;
$platforms = DeviceAttack::platforms($model->device_type, $model->os, $model->manufacturer);
$observed = $incidents ? AttackTechnique::find()->with('parent')
    ->innerJoin(['l' => 'incident_attack_technique'], 'l.technique_id = attack_technique.id')
    ->where(['l.incident_id' => array_map(static fn($i) => $i->id, $incidents)])
    ->distinct()->all() : [];
// ATT&CK mitigation ids shown next to each device check
$attackIds = static fn(\app\modules\inventory\components\DeviceCheck $check) => implode('', array_map(
    static fn($id) => Html::a(Html::encode($id), ['/attack/mitigation/view', 'id' => $id], ['class' => 'gw-checklist__attack', 'title' => 'ATT&CK mitigation ' . $id]),
    $check->mitigations
));
$resultIcons = [DevicePosture::RESULT_PASS => ['is-ok', 'check-circle-fill'], DevicePosture::RESULT_FAIL => ['is-fail', 'x-circle-fill'], DevicePosture::RESULT_UNKNOWN => ['is-unknown', 'question-circle']];
$skipped = count(DeviceChecks::all()) - count($posture['items']);

ob_start(); ?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Device</h2>
            <div class="row">
                <div class="col-lg-6">
                    <?= DetailView::widget([
                        'model' => $model,
                        'attributes' => [
                            ['attribute' => 'asset_tag', 'contentOptions' => ['class' => 'gw-mono']],
                            'name',
                            ['attribute' => 'hostname', 'contentOptions' => ['class' => 'gw-mono']],
                            ['attribute' => 'device_type', 'value' => $model->getTypeLabel()],
                            ['attribute' => 'ownership', 'format' => 'raw', 'value' => Badge::render('ownership', $model->ownership, $model->getOwnershipLabel())],
                            'manufacturer',
                            'model',
                            ['attribute' => 'serial_number', 'contentOptions' => ['class' => 'gw-mono']],
                            'os',
                            ['attribute' => 'os_version', 'contentOptions' => ['class' => 'gw-mono']],
                            [
                                'label' => 'OS support',
                                'format' => 'raw',
                                'visible' => (bool) $model->os,
                                'value' => $osSupport['endDate']
                                    ? Html::encode(($osSupport['state'] === OsLifecycle::UNSUPPORTED ? 'Ended ' : 'Until ') . $fmt->asDate($osSupport['endDate']))
                                        . ($osSupport['estimated'] ? ' <span class="gw-muted">(estimated)</span>' : '') . ' ' . Badge::osSupport($osSupport)
                                    : '<span class="gw-muted">Unknown for this OS / version</span>',
                            ],
                        ],
                    ]) ?>
                </div>
                <div class="col-lg-6">
                    <?= DetailView::widget([
                        'model' => $model,
                        'attributes' => [
                            'assigned_to',
                            ['attribute' => 'assigned_email', 'format' => 'raw', 'value' => $model->assigned_email ? Html::mailto(Html::encode($model->assigned_email), $model->assigned_email) : null],
                            ['attribute' => 'department_id', 'value' => $model->department->name ?? null],
                            ['attribute' => 'location_id', 'value' => $model->location->name ?? null],
                            ['attribute' => 'ip_addresses', 'format' => 'raw', 'value' => $model->getIpList() ? implode('<br>', array_map(static fn($ip) => '<code class="gw-code">' . Html::encode($ip) . '</code>', $model->getIpList())) : null],
                            ['attribute' => 'mac_address', 'format' => 'raw', 'value' => $model->mac_address ? '<code class="gw-code">' . Html::encode($model->mac_address) . '</code>' : null],
                            ['attribute' => 'criticality', 'format' => 'raw', 'value' => Badge::criticality($model->criticality)],
                            ['attribute' => 'data_classification', 'format' => 'raw', 'value' => Badge::render('classification', $model->data_classification, Device::classificationOptions()[$model->data_classification] ?? null)],
                        ],
                    ]) ?>
                </div>
            </div>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Notes', 'text' => $model->notes]) ?>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Lifecycle</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    'purchase_date:date',
                    ['attribute' => 'warranty_expiry', 'format' => 'raw', 'value' => $fmt->asDate($model->warranty_expiry) . ' ' . ($model->isWarrantyExpiringSoon() ? Badge::flag('expiring', 'Expiring soon') : ($model->isWarrantyExpired() ? Badge::flag('expired', 'Expired') : ''))],
                    ['attribute' => 'eol_date', 'format' => 'raw', 'value' => $fmt->asDate($model->eol_date) . ' ' . ($model->isPastEol() ? Badge::flag('eol', 'Past EOL') : '')],
                ],
            ]) ?>
        </section>
        <?php if ($model->source || $model->last_seen_at): ?>
        <section class="gw-panel gw-device-sync">
            <h2 class="gw-panel__title"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Sync</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'source', 'value' => $model->getSourceLabel()],
                    ['attribute' => 'source_id', 'contentOptions' => ['class' => 'gw-mono']],
                    'first_seen_at:datetime',
                    ['attribute' => 'last_seen_at', 'format' => 'raw', 'value' => $model->last_seen_at
                        ? $fmt->asDatetime($model->last_seen_at) . ' <span class="gw-muted">(' . $fmt->asRelativeTime($model->last_seen_at) . ')</span> '
                            . Badge::lastSeen($model)
                        : null],
                ],
            ]) ?>
            <p class="gw-muted gw-small">Refreshed by each device import. The export has no security-posture data, so record the device checks on this record.</p>
        </section>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel gw-posture gw-posture--<?= Html::encode($posture['state']) ?>">
            <h2 class="gw-panel__title">Security posture</h2>
            <div class="gw-posture__status"><?= Badge::posture($posture['state']) ?></div>
            <?php if (!$posture['items']): ?>
                <p class="gw-empty">No device check applies to this device.</p>
            <?php else: ?>
            <ul class="gw-checklist">
                <?php foreach ($posture['items'] as $id => $item): [$cls, $icon] = $resultIcons[$item['result']]; ?>
                    <li class="gw-checklist__item <?= $cls ?>">
                        <i class="bi bi-<?= $icon ?>" aria-hidden="true" title="<?= Html::encode(DevicePosture::resultLabels()[$item['result']]) ?>"></i>
                        <span><?= Html::a(Html::encode($item['check']->title), ['/control/control/view', 'id' => $id], ['class' => 'gw-checklist__link']) ?>
                            <?php if ($item['detail'] || $item['result'] === DevicePosture::RESULT_UNKNOWN): ?>
                                <span class="gw-muted">· <?= Html::encode($item['detail'] ?? 'Not assessed') ?></span>
                            <?php endif; ?></span>
                        <?= $attackIds($item['check']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($skipped > 0): ?>
                <p class="gw-muted gw-small mt-2 mb-0"><?= $skipped ?> other device check(s) do not apply to this device type or ownership.</p>
            <?php endif; ?>
        </section>
        <section class="gw-panel gw-device-attack">
            <h2 class="gw-panel__title"><i class="bi bi-crosshair" aria-hidden="true"></i> ATT&amp;CK</h2>
            <dl class="gw-dl">
                <dt>Platform</dt>
                <dd><?= $platforms ? Html::encode(implode(', ', $platforms)) : '<span class="gw-muted">' . Html::encode(DeviceAttack::noPlatformReason($model->device_type)) . '</span>' ?></dd>
                <dt>Observed</dt>
                <dd><?= TechniqueTags::widget(['items' => $observed, 'compact' => true, 'emptyText' => 'No techniques recorded on linked incidents']) ?></dd>
            </dl>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Record</h2>
            <?= $this->render('@app/views/partials/_record-meta', ['model' => $model]) ?>
        </section>
    </div>
</div>
<?php $details = ob_get_clean();

ob_start(); ?>
<p class="gw-muted">Links are managed from the risk and incident records.</p>
<div class="row g-4">
    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'Linked risks', 'icon' => 'exclamation-triangle', 'models' => $risks,
        'extra' => static fn(Risk $r) => Badge::score($r->getCurrentScore()) . ' ' . Badge::status('risk-status', $r->status, Risk::statusOptions())]) ?></div>
    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'Linked incidents', 'icon' => 'fire', 'models' => $incidents,
        'extra' => static fn(Incident $i) => Badge::severity($i->severity) . ' ' . Badge::status('incident-status', $i->status, Incident::statusOptions())]) ?></div>
</div>
<?php $links = ob_get_clean(); ?>

<?= $this->render('@app/views/partials/_archived-notice', ['model' => $model]) ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => [
        ['label' => 'Details', 'content' => $details, 'options' => ['id' => 'tab-details']],
        ['label' => 'Risks & incidents (' . (count($risks) + count($incidents)) . ')', 'content' => $links, 'options' => ['id' => 'tab-links']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'device', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
