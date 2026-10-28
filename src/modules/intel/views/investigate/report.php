<?php
/**
 * IP address / ASN investigation report.
 *
 * @var yii\web\View $this
 * @var app\modules\intel\components\Indicator $indicator
 * @var app\modules\intel\components\Report $report
 * @var app\modules\intel\models\IntelIndicator $model
 * @var app\modules\intel\components\LocalContext $local
 * @var app\modules\intel\models\IntelNote $note
 * @var app\modules\intel\models\BlocklistEntry $block
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\intel\components\IntelHtml;
use app\modules\intel\components\SourceRegistry;
use app\modules\intel\components\sources\Source;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$a = $report->assessment();
$f = $report->facts;
$asn = $indicator->isAsn() ? $indicator->asn : ($f['asn'] ?? null);

$this->title = $indicator->value;
$this->params['breadcrumbs'][] = ['label' => 'IP Investigation', 'url' => ['index']];
$this->params['breadcrumbs'][] = $indicator->value;
$this->params['subtitle'] = '<span class="gw-intel-kind">' . Html::encode($indicator->isIp() ? 'IPv' . $indicator->ipVersion() : 'ASN') . '</span> '
    . Badge::intelRating($a->rating, $a->score)
    . ($model->verdict ? ' ' . Badge::verdict($model->verdict) : '')
    . ($local->activeBlocks() ? ' ' . Badge::blockState('blocked') : '')
    . ($indicator->isIp() && $asn ? ' ' . Html::a('AS' . $asn . (isset($f['as_name']) ? ' · ' . Html::encode($f['as_name']) : ''), ['asn', 'asn' => 'AS' . $asn], ['class' => 'gw-intel-subtitle-link']) : '')
    . ($indicator->isAsn() && isset($f['as_name']) ? ' <span class="gw-intel-subtitle-text">' . Html::encode($f['as_name']) . '</span>' : '');

$actions = [];
$actions[] = Html::button('<i class="bi bi-copy"></i>', ['class' => 'btn btn-outline-secondary gw-btn gw-btn--icon', 'data-gw-copy' => $indicator->value, 'title' => 'Copy ' . $indicator->value, 'aria-label' => 'Copy indicator']);
$actions[] = Html::a('<i class="bi bi-arrow-clockwise"></i> Re-run', $indicator->url(['refresh' => 1]), ['class' => 'btn btn-outline-secondary gw-btn', 'data-gw-scan' => '1', 'title' => 'Query every source again, bypassing the cache']);
if (Rbac::canManage()) {
    $actions[] = Html::button('<i class="bi bi-slash-circle"></i> Block', ['class' => 'btn btn-danger gw-btn gw-btn--block', 'data-bs-toggle' => 'modal', 'data-bs-target' => '#gw-block-modal']);
}
$this->params['actions'] = implode("\n", $actions);

// Source panels grouped by category (registration lives on its own tab)
$groups = [];
foreach ($report->results as $key => $result) {
    $source = SourceRegistry::get($key);
    if ($source->category() !== Source::CAT_REGISTRY) {
        $groups[$source->category()][$key] = $result;
    }
}
$order = array_keys(SourceRegistry::categoryLabels());
uksort($groups, static fn($x, $y) => array_search($x, $order, true) <=> array_search($y, $order, true));

ob_start(); ?>
<?php foreach ($groups as $category => $results): ?>
    <section class="gw-intel-group" aria-labelledby="grp-<?= $category ?>">
        <h2 class="gw-intel-group__title" id="grp-<?= $category ?>"><?= Html::encode(SourceRegistry::categoryLabels()[$category]) ?></h2>
        <?php if (count($results) === 1): ?>
            <div class="gw-intel-grid gw-intel-grid--single">
                <?= $this->render('_source-panel', ['key' => key($results), 'source' => SourceRegistry::get(key($results)), 'result' => current($results), 'indicator' => $indicator]) ?>
            </div>
        <?php else: ?>
            <div class="gw-intel-grid">
                <?php foreach (IntelHtml::stacks($results) as $stack): ?>
                    <div class="gw-intel-stack">
                        <?php foreach ($stack as $key => $position): ?>
                            <?= $this->render('_source-panel', ['key' => $key, 'source' => SourceRegistry::get($key), 'result' => $results[$key], 'indicator' => $indicator, 'position' => $position]) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
<?php $intel = ob_get_clean();

$notes = $model->notes;
$correlations = count($local->blocks) + count($local->devices) + count($local->incidents);
$items = [
    ['label' => '<i class="bi bi-radar"></i> Intelligence', 'encode' => false, 'content' => $intel, 'options' => ['id' => 'tab-intel']],
];
if ($indicator->isAsn()) {
    $items[] = ['label' => '<i class="bi bi-diagram-2"></i> Prefixes (' . count($f['prefixes'] ?? []) . ')', 'encode' => false, 'content' => $this->render('_prefixes', ['report' => $report]), 'options' => ['id' => 'tab-prefixes']];
}
$items[] = ['label' => '<i class="bi bi-journal-text"></i> WHOIS &amp; RDAP', 'encode' => false, 'content' => $this->render('_registry', ['report' => $report]), 'options' => ['id' => 'tab-registry']];
$items[] = ['label' => '<i class="bi bi-sticky"></i> Notes (' . count($notes) . ')', 'encode' => false, 'content' => $this->render('_notes', ['model' => $model, 'notes' => $notes, 'note' => $note]), 'options' => ['id' => 'tab-notes']];
$items[] = ['label' => '<i class="bi bi-link-45deg"></i> Correlation (' . $correlations . ')', 'encode' => false, 'content' => $this->render('_correlation', ['indicator' => $indicator, 'local' => $local, 'model' => $model]), 'options' => ['id' => 'tab-correlation']];
$items[] = ['label' => '<i class="bi bi-box-arrow-up-right"></i> Pivot', 'encode' => false, 'content' => $this->render('_pivots', ['indicator' => $indicator]), 'options' => ['id' => 'tab-pivot']];
?>
<div class="gw-intel-report" data-indicator="<?= Html::encode($indicator->value) ?>">
    <?= $this->render('_assessment', ['report' => $report, 'model' => $model, 'local' => $local]) ?>

    <?= Tabs::widget([
        'options' => ['class' => 'gw-tabs gw-intel-tabs'],
        'tabContentOptions' => ['class' => 'gw-tab-content'],
        'items' => $items,
    ]) ?>

    <p class="gw-intel-disclaimer">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        The score is generated automatically from third-party intelligence, so verify it before acting on it.
        <?= $indicator->isPublic() ? 'The indicator was sent to the external sources listed above; ' . Html::a('manage sources', ['/intel/source/index']) . '.' : 'Nothing was sent to external services.' ?>
    </p>
</div>

<?php if (Rbac::canManage()): ?>
    <?= $this->render('_block-modal', ['indicator' => $indicator, 'report' => $report, 'block' => $block, 'local' => $local]) ?>
<?php endif; ?>
<?= $this->render('_scan-overlay', ['indicator' => $indicator]) ?>
