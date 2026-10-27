<?php
/**
 * @var yii\web\View $this
 * @var app\modules\intel\models\BlocklistEntry $model
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\components\IpAddress;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\IntelIndicator;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$isAsn = $model->indicator_type === BlocklistEntry::TYPE_ASN;
$this->title = $model->value;
$this->params['breadcrumbs'][] = ['label' => 'Blocklist', 'url' => ['index', 'kind' => $isAsn ? 'asn' : 'ip']];
$this->params['breadcrumbs'][] = $model->reference;
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::blockState($model->getState()) . ' ' . Badge::severity($model->severity) . ' '
    . Badge::render('block-type', $model->indicator_type, $model->getTypeLabel());

$buttons = [];
if ($url = $model->getIndicatorUrl()) {
    $buttons[] = Html::a('<i class="bi bi-radar"></i> Investigate', $url, ['class' => 'btn btn-outline-secondary gw-btn']);
}
if (Rbac::canManage()) {
    if (!$model->getIsArchived()) {
        $buttons[] = Html::a('<i class="bi bi-pencil-square"></i> Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-primary gw-btn gw-btn--edit']);
        $buttons[] = Html::a('<i class="bi bi-unlock"></i> Unblock', ['archive', 'id' => $model->id], [
            'class' => 'btn btn-outline-warning gw-btn gw-btn--archive', 'data-method' => 'post',
            'data-confirm' => sprintf('Unblock %s? The entry is kept (Unblocked filter) and can be re-instated.', $model->value),
        ]);
    } else {
        $buttons[] = Html::a('<i class="bi bi-slash-circle"></i> Block again', ['restore', 'id' => $model->id], ['class' => 'btn btn-outline-danger gw-btn gw-btn--restore', 'data-method' => 'post']);
    }
}
if (Rbac::canDelete() && $model->getIsArchived()) {
    $buttons[] = Html::a('<i class="bi bi-trash"></i> Delete permanently', ['delete', 'id' => $model->id], [
        'class' => 'btn btn-outline-danger gw-btn gw-btn--delete', 'data-method' => 'post',
        'data-confirm' => sprintf('Permanently delete %s? This cannot be undone.', $model->reference),
    ]);
}
$this->params['actions'] = implode("\n", $buttons);

$fmt = Yii::$app->formatter;
$intel = IntelIndicator::findOne(['type' => $isAsn ? 'asn' : 'ip', 'value' => $model->indicator_type === BlocklistEntry::TYPE_CIDR ? explode('/', $model->value)[0] : $model->value]);
$coverage = null;
if ($model->range_start) {
    $prefix = $model->indicator_type === BlocklistEntry::TYPE_CIDR ? (int) explode('/', $model->value)[1] : null;
    $version = IpAddress::version(IpAddress::fromHex($model->range_start));
    $coverage = IpAddress::fromHex($model->range_start) . ($model->range_start !== $model->range_end ? ' – ' . IpAddress::fromHex($model->range_end) : '')
        . ($prefix !== null && $version === 4 ? ' (' . number_format(IpAddress::prefixSize(4, $prefix)) . ' addresses)' : '');
}

// Copy-ready firewall rules (IP / CIDR; Cloudflare also for ASNs)
$rules = [];
if ($model->direction !== 'outbound') {
    $rules['cloudflare'][] = $model->getCloudflareClause();
}
if (!$isAsn) {
    $v6 = str_contains($model->value, ':');
    $ipt = $v6 ? 'ip6tables' : 'iptables';
    $fam = $v6 ? 'ip6' : 'ip';
    $comment = $model->reference;
    if ($model->direction !== 'outbound') {
        $rules['iptables'][] = "$ipt -I INPUT -s {$model->value} -m comment --comment \"$comment\" -j DROP";
        $rules['nftables'][] = "nft insert rule inet filter input $fam saddr {$model->value} counter drop comment \"$comment\"";
        $rules['pf'][] = "block in quick from {$model->value} label \"$comment\"";
    }
    if ($model->direction !== 'inbound') {
        $rules['iptables'][] = "$ipt -I OUTPUT -d {$model->value} -m comment --comment \"$comment\" -j DROP";
        $rules['nftables'][] = "nft insert rule inet filter output $fam daddr {$model->value} counter drop comment \"$comment\"";
        $rules['pf'][] = "block out quick to {$model->value} label \"$comment\"";
    }
}

ob_start(); ?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Block</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'reference', 'contentOptions' => ['class' => 'gw-mono']],
                    ['attribute' => 'value', 'format' => 'raw', 'value' => Html::tag('span', Html::encode($model->value), ['class' => 'gw-mono'])],
                    ['attribute' => 'indicator_type', 'value' => $model->getTypeLabel()],
                    ['label' => 'Covers', 'format' => 'raw', 'visible' => $coverage !== null, 'value' => '<span class="gw-mono">' . Html::encode((string) $coverage) . '</span>'],
                    ['attribute' => 'severity', 'format' => 'raw', 'value' => Badge::severity($model->severity)],
                    ['attribute' => 'direction', 'value' => BlocklistEntry::directionOptions()[$model->direction] ?? $model->direction],
                    ['label' => 'State', 'format' => 'raw', 'value' => Badge::blockState($model->getState())],
                    ['attribute' => 'expires_at', 'format' => 'raw', 'value' => $model->expires_at ? $fmt->asDatetime($model->expires_at) . ($model->getIsExpired() ? ' ' . Badge::blockState('expired') : '') : '<span class="gw-muted">Permanent</span>'],
                    ['attribute' => 'incident_id', 'format' => 'raw', 'value' => $model->incident ? Html::a(Html::encode($model->incident->reference), $model->incident->getViewUrl(), ['class' => 'gw-ref']) . ' ' . Html::encode($model->incident->title) : null],
                    [
                        'label' => 'Cloudflare',
                        'format' => 'raw',
                        'visible' => Cloudflare::isConfigured() || $model->cloudflare_added_at !== null,
                        'value' => $model->cloudflare_added_at
                            ? '<i class="bi bi-cloud-check gw-intel-cf" aria-hidden="true"></i> In the “' . Html::encode(Cloudflare::ruleName()) . '” rule since ' . $fmt->asDatetime($model->cloudflare_added_at)
                            : '<span class="gw-muted">Not added</span>',
                    ],
                ],
            ]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Reason', 'text' => $model->reason]) ?>
        </section>
        <?php if ($rules): ?>
            <section class="gw-panel">
                <h2 class="gw-panel__title"><i class="bi bi-terminal" aria-hidden="true"></i> Firewall rules</h2>
                <p class="gw-muted gw-attack-tab__intro">Copy-ready examples; adapt the table and chain names to your firewall. The Cloudflare line is the condition “Add to Cloudflare” puts in the WAF rule.</p>
                <?php foreach ($rules as $engine => $lines): ?>
                    <div class="gw-terminal gw-terminal--compact">
                        <div class="gw-terminal__bar" aria-hidden="true"><span></span><span></span><span></span><code><?= $engine ?></code></div>
                        <button type="button" class="gw-terminal__copy" data-gw-copy-target="#fw-<?= $engine ?>" title="Copy"><i class="bi bi-copy"></i><span class="visually-hidden">Copy</span></button>
                        <pre class="gw-terminal__body" id="fw-<?= $engine ?>"><?= Html::encode(implode("\n", $lines)) ?></pre>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-radar" aria-hidden="true"></i> Intelligence</h2>
            <?php if ($intel && $intel->last_checked_at): ?>
                <dl class="gw-dl">
                    <dt>Last assessment</dt><dd><?= Badge::intelRating($intel->last_rating, $intel->last_score) ?></dd>
                    <dt>Analyst verdict</dt><dd><?= $intel->verdict ? Badge::verdict($intel->verdict) : '<span class="gw-muted">none</span>' ?></dd>
                    <dt>Owner</dt><dd><?= Html::encode((string) $intel->summary) ?: '—' ?></dd>
                    <dt>Investigated</dt><dd><?= $fmt->asDatetime($intel->last_checked_at) ?></dd>
                </dl>
            <?php else: ?>
                <p class="gw-empty">Not investigated yet.</p>
            <?php endif; ?>
            <?php if ($url = $model->getIndicatorUrl()): ?>
                <p class="mb-0 mt-3"><?= Html::a('Open investigation', $url, ['class' => 'gw-panel__link']) ?></p>
            <?php endif; ?>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Record</h2>
            <?= $this->render('@app/views/partials/_record-meta', ['model' => $model]) ?>
        </section>
    </div>
</div>
<?php $details = ob_get_clean(); ?>

<?php if ($model->getIsArchived()): ?>
    <div class="alert alert-secondary gw-callout" role="status">
        <i class="bi bi-unlock" aria-hidden="true"></i>
        Unblocked on <?= $fmt->asDatetime($model->archived_at) ?> by <?= Html::encode($model->archiver->full_name ?? 'system') ?>. The entry no longer applies.
    </div>
<?php elseif ($model->getIsExpired()): ?>
    <div class="alert alert-warning gw-callout" role="status">
        <i class="bi bi-hourglass-bottom" aria-hidden="true"></i>
        This block expired on <?= $fmt->asDatetime($model->expires_at) ?>. Edit it to extend the expiry, or unblock it.
    </div>
<?php endif; ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => [
        ['label' => 'Details', 'content' => $details, 'options' => ['id' => 'tab-details']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'blocklist', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
