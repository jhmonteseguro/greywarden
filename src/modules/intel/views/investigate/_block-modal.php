<?php
/**
 * "Block" dialog on a report: block the address, its BGP prefix or its origin AS.
 * Posts to the blocklist create action, which returns to this report.
 *
 * @var yii\web\View $this
 * @var app\modules\intel\components\Indicator $indicator
 * @var app\modules\intel\components\Report $report
 * @var app\modules\intel\models\BlocklistEntry $block
 * @var app\modules\intel\components\LocalContext $local
 */

use app\modules\incident\models\Incident;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\models\BlocklistEntry;
use yii\helpers\Html;

$a = $report->assessment();
$f = $report->facts;
$targets = [];
if ($indicator->isIp()) {
    $targets[] = ['value' => $indicator->value, 'label' => 'This address', 'hint' => $indicator->value];
    if (!empty($f['bgp_prefix']) && $f['bgp_prefix'] !== $indicator->value) {
        $targets[] = ['value' => $f['bgp_prefix'], 'label' => 'Its network', 'hint' => $f['bgp_prefix'] . ' (BGP prefix)'];
    }
    if (!empty($f['asn'])) {
        $targets[] = ['value' => 'AS' . $f['asn'], 'label' => 'Its origin AS', 'hint' => 'AS' . $f['asn'] . (isset($f['as_name']) ? ' · ' . $f['as_name'] : '')];
    }
} else {
    $targets[] = ['value' => $indicator->value, 'label' => 'This autonomous system', 'hint' => $indicator->value . (isset($f['as_name']) ? ' · ' . $f['as_name'] : '')];
}
$blocked = array_map(static fn(BlocklistEntry $b) => $b->value, $local->activeBlocks());
// Pre-select the first target that is not blocked yet
$selected = null;
foreach ($targets as $n => $t) {
    if (!in_array($t['value'], $blocked, true)) {
        $selected = $n;
        break;
    }
}
if ($selected !== null) {
    $block->value = $targets[$selected]['value'];
}
$reason = sprintf('Automated assessment: %s%s. %s', $a->ratingLabel(), $a->rating !== 'special' ? ' (score ' . $a->score . ')' : '', $a->headline);
?>
<div class="modal fade gw-intel-block-modal" id="gw-block-modal" tabindex="-1" aria-labelledby="gw-block-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <?= Html::beginForm(['/intel/blocklist/create'], 'post', ['id' => 'gw-block-form']) ?>
            <?= Html::hiddenInput('return', 'report') ?>
            <div class="modal-header">
                <h2 class="modal-title" id="gw-block-title"><span class="gw-intel-block-modal__icon" aria-hidden="true"><i class="bi bi-slash-circle"></i></span> Add to blocklist</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>
            </div>
            <div class="modal-body">
                <fieldset class="gw-intel-targets">
                    <legend class="gw-field__label form-label">What to block</legend>
                    <?php foreach ($targets as $n => $t):
                        $isBlocked = in_array($t['value'], $blocked, true); ?>
                        <label class="gw-intel-target<?= $isBlocked ? ' is-blocked' : '' ?>">
                            <input type="radio" name="gw-block-target" value="<?= Html::encode($t['value']) ?>" data-gw-fill="#blocklistentry-value" <?= $n === $selected ? 'checked' : '' ?> <?= $isBlocked ? 'disabled' : '' ?>>
                            <span class="gw-intel-target__label"><?= Html::encode($t['label']) ?></span>
                            <span class="gw-intel-target__value gw-mono"><?= Html::encode($t['hint']) ?></span>
                            <?php if ($isBlocked): ?><span class="gw-intel-target__state">already blocked</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if ($selected === null): ?>
                        <p class="gw-muted mb-0 gw-intel-method">Everything above is already blocked. You can still enter another address, network or ASN below.</p>
                    <?php endif; ?>
                </fieldset>
                <div class="row">
                    <div class="col-md-5 mb-3 gw-field">
                        <label class="form-label gw-field__label" for="blocklistentry-value">IP, network or ASN</label>
                        <?= Html::activeTextInput($block, 'value', ['class' => 'form-control gw-mono', 'maxlength' => 64, 'required' => true]) ?>
                    </div>
                    <div class="col-md-3 mb-3 gw-field">
                        <label class="form-label gw-field__label" for="blocklistentry-severity">Severity</label>
                        <?= Html::activeDropDownList($block, 'severity', BlocklistEntry::severityOptions(), ['class' => 'form-select']) ?>
                    </div>
                    <div class="col-md-4 mb-3 gw-field">
                        <label class="form-label gw-field__label" for="blocklistentry-direction">Direction</label>
                        <?= Html::activeDropDownList($block, 'direction', BlocklistEntry::directionOptions(), ['class' => 'form-select']) ?>
                    </div>
                    <div class="col-md-6 mb-3 gw-field">
                        <label class="form-label gw-field__label" for="blocklistentry-expires_at">Expires</label>
                        <?= Html::activeInput('datetime-local', $block, 'expires_at', ['class' => 'form-control', 'step' => 60]) ?>
                        <div class="gw-intel-quick" role="group" aria-label="Quick expiry">
                            <?php foreach (['24 h' => 24, '7 days' => 168, '30 days' => 720, '90 days' => 2160, 'Permanent' => 0] as $label => $hours): ?>
                                <button type="button" class="gw-intel-quick__btn" data-gw-expiry="<?= $hours ?>" data-gw-target="#blocklistentry-expires_at"><?= $label ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3 gw-field">
                        <label class="form-label gw-field__label" for="blocklistentry-incident_id">Related incident</label>
                        <?= Html::activeDropDownList($block, 'incident_id', Incident::selectOptions(array_filter([$block->incident_id])), ['class' => 'form-select', 'prompt' => '— None —']) ?>
                    </div>
                    <div class="col-12 gw-field">
                        <label class="form-label gw-field__label" for="blocklistentry-reason">Reason</label>
                        <?= Html::activeTextarea($block, 'reason', ['class' => 'form-control', 'rows' => 3, 'required' => true, 'value' => $block->reason ?: $reason]) ?>
                    </div>
                    <div class="col-12 mt-3 gw-field">
                        <div class="form-check">
                            <?= Html::activeCheckbox($block, 'cloudflare', ['class' => 'form-check-input', 'label' => false, 'disabled' => !Cloudflare::isConfigured(), 'aria-describedby' => 'gw-block-cloudflare-hint']) ?>
                            <label class="form-check-label" for="blocklistentry-cloudflare"><?= Html::encode($block->getAttributeLabel('cloudflare')) ?></label>
                            <div class="form-text" id="gw-block-cloudflare-hint"><?= Html::encode($block->getAttributeHint('cloudflare')) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <?= Html::submitButton('<i class="bi bi-slash-circle"></i> Block', ['class' => 'btn btn-danger']) ?>
            </div>
            <?= Html::endForm() ?>
        </div>
    </div>
</div>
