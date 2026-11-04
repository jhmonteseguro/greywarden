<?php
/**
 * The feeds Threat Watch reads, how they are doing, and recent reads.
 *
 * @var yii\web\View $this
 * @var app\modules\threat\models\ThreatSource[] $sources
 * @var app\modules\threat\models\ThreatPull[] $pulls newest first
 * @var app\modules\threat\models\ThreatSource $model empty source for the form
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\threat\components\ThreatSettings;
use app\modules\threat\models\ThreatSource;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

$this->title = 'News Sources';
$this->params['breadcrumbs'][] = ['label' => 'Threat Watch', 'url' => ['/threat/story/index']];
$this->params['breadcrumbs'][] = 'Sources';
$fmt = Yii::$app->formatter;
$canSettings = Rbac::can(Rbac::PERM_SETTINGS);
$on = array_filter($sources, static fn(ThreatSource $s) => (bool) $s->is_enabled);
$failing = array_filter($on, static fn(ThreatSource $s) => $s->getHealth() === 'failing');
$this->params['subtitle'] = sprintf('%d of %d sources on%s', count($on), count($sources), $failing ? sprintf(', %d failing', count($failing)) : '');
$this->params['actions'] = Rbac::canManage() ? Html::a('<i class="bi bi-cloud-arrow-down"></i> Read now', ['pull'], ['class' => 'btn btn-primary gw-btn', 'data-method' => 'post']) : '';
$groups = ArrayHelper::index($sources, null, 'category');
$health = ['ok' => 'Working', 'failing' => 'Failing', 'waiting' => 'Not read yet', 'off' => 'Off'];
?>
<?php if (!ThreatSettings::nvdApiKey()): ?>
    <div class="alert alert-info gw-callout" role="status">
        <i class="bi bi-key" aria-hidden="true"></i>
        <span>CVSS scores arrive a few at a time without a free NVD API key. Set <code>NVD_API_KEY</code> in <code>.env</code> and run <code>make up</code> for them to arrive ten times faster.</span>
    </div>
<?php endif; ?>

<?php foreach (ThreatSource::categoryOptions() as $category => $label): if (empty($groups[$category])) continue; ?>
    <section class="gw-panel gw-panel--flush">
        <div class="gw-panel__header gw-panel__header--flush">
            <h2 class="gw-panel__title"><?= Html::encode($label) ?> <span class="gw-panel__count"><?= count($groups[$category]) ?></span></h2>
        </div>
        <div class="table-responsive">
            <table class="table gw-table gw-threat-sources">
                <thead><tr><th scope="col">Source</th><th scope="col">Status</th><th scope="col" class="gw-threat-col-aux">Last read</th><th scope="col" class="text-end gw-threat-col-aux">Articles</th><?php if ($canSettings): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($groups[$category] as $source): $state = $source->getHealth(); ?>
                    <tr>
                        <td>
                            <?= Html::encode($source->name) ?>
                            <?php if ($source->is_quiet): ?><span class="gw-muted" title="Too many items to read: they stay out of the queue unless they matter to you"> · background</span><?php endif; ?>
                            <?php if ($source->is_custom): ?><span class="gw-muted"> · custom</span><?php endif; ?>
                            <?php if ($source->last_error): ?><div class="gw-threat-error"><?= Html::encode($source->last_error) ?></div><?php endif; ?>
                        </td>
                        <td><?= Badge::render('threat-health', $state, $health[$state]) ?></td>
                        <td class="gw-threat-col-aux"><?= $source->last_success_at ? $fmt->asRelativeTime($source->last_success_at) : '<span class="gw-muted">Never</span>' ?></td>
                        <td class="text-end gw-mono gw-threat-col-aux"><?= number_format($source->items_total) ?></td>
                        <?php if ($canSettings): ?>
                            <td class="text-end gw-threat-terms__actions">
                                <?= Html::a($source->is_enabled ? 'Switch off' : 'Switch on', ['toggle', 'id' => $source->id], ['class' => 'btn btn-outline-secondary btn-sm', 'data-method' => 'post']) ?>
                                <?php if ($source->is_custom): ?>
                                    <?= Html::a('Remove', ['delete', 'id' => $source->id], ['class' => 'btn btn-outline-secondary btn-sm', 'data-method' => 'post', 'data-confirm' => sprintf('Remove %s and its articles?', $source->name)]) ?>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<?php if ($canSettings): ?>
    <section class="gw-panel">
        <h2 class="gw-panel__title"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add a feed</h2>
        <p class="gw-muted gw-threat-note">An RSS or Atom feed on the public internet, over https. It is read once before it is added. Sources are shared by the whole instance.</p>
        <?= Html::beginForm(['create'], 'post', ['class' => 'gw-threat-add']) ?>
            <div class="gw-field">
                <label class="form-label gw-field__label" for="source-name">Name</label>
                <?= Html::activeTextInput($model, 'name', ['id' => 'source-name', 'class' => 'form-control', 'maxlength' => 120, 'required' => true]) ?>
            </div>
            <div class="gw-field gw-threat-add__wide">
                <label class="form-label gw-field__label" for="source-url">Feed address</label>
                <?= Html::activeInput('url', $model, 'url', ['id' => 'source-url', 'class' => 'form-control', 'maxlength' => 1000, 'required' => true, 'placeholder' => 'https://example.com/feed/']) ?>
            </div>
            <div class="gw-field">
                <label class="form-label gw-field__label" for="source-category">Type</label>
                <?= Html::activeDropDownList($model, 'category', ThreatSource::categoryOptions(), ['id' => 'source-category', 'class' => 'form-select']) ?>
            </div>
            <?= Html::submitButton('<i class="bi bi-plus-lg"></i> Add', ['class' => 'btn btn-primary gw-btn']) ?>
        <?= Html::endForm() ?>
    </section>
<?php endif; ?>

<section class="gw-panel gw-panel--flush">
    <div class="gw-panel__header gw-panel__header--flush">
        <h2 class="gw-panel__title"><i class="bi bi-clock-history" aria-hidden="true"></i> Recent reads</h2>
    </div>
    <?php if (!$pulls): ?>
        <p class="gw-empty">Nothing read yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table gw-table">
                <thead><tr><th scope="col">When</th><th scope="col">Result</th><th scope="col" class="text-end">Read</th><th scope="col" class="text-end">New articles</th><th scope="col" class="text-end">New stories</th><th scope="col" class="text-end">CVEs enriched</th></tr></thead>
                <tbody>
                <?php foreach ($pulls as $pull): ?>
                    <tr>
                        <td><?= $fmt->asDatetime($pull->started_at) ?><?php if ($pull->message): ?><div class="gw-threat-error"><?= Html::encode($pull->message) ?></div><?php endif; ?></td>
                        <td><?= Badge::render('edge-pull', $pull->status) ?></td>
                        <td class="text-end gw-mono"><?= (int) $pull->sources_read ?></td>
                        <td class="text-end gw-mono"><?= (int) $pull->articles_new ?></td>
                        <td class="text-end gw-mono"><?= (int) $pull->stories_new ?></td>
                        <td class="text-end gw-mono"><?= (int) $pull->cves_enriched ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
