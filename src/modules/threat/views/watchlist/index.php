<?php
/**
 * The organisation's watchlist, by group.
 *
 * @var yii\web\View $this
 * @var app\modules\threat\models\ThreatWatchTerm[] $terms
 * @var app\modules\threat\models\ThreatWatchTerm $model empty term for the form
 * @var array<string, int> $matches term => stories mentioning it
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\threat\models\ThreatWatchTerm;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

$this->title = 'Watchlist';
$this->params['breadcrumbs'][] = ['label' => 'Threat Watch', 'url' => ['/threat/story/index']];
$this->params['breadcrumbs'][] = 'Watchlist';
$this->params['subtitle'] = 'What you run. Stories naming these rank higher in Threat Watch.';
$canManage = Rbac::canManage();
$this->params['actions'] = $canManage ? Html::a('<i class="bi bi-arrow-repeat"></i> Update from assets', ['sync'], ['class' => 'btn btn-outline-secondary gw-btn', 'data-method' => 'post']) : '';
$groups = ArrayHelper::index($terms, null, 'category');
$categories = ThreatWatchTerm::categoryOptions();
$origins = ThreatWatchTerm::originOptions();
$formCategories = array_diff_key($categories, array_flip(['device', 'supplier']));
?>
<?php if ($canManage): ?>
    <section class="gw-panel">
        <h2 class="gw-panel__title"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add a term</h2>
        <p class="gw-muted gw-threat-note">Whole words only. Terms of up to four letters match their exact case, so <code>Duo</code> does not match "the duo". Be specific: a product name finds more signal than a vendor name.</p>
        <?= Html::beginForm(['create'], 'post', ['class' => 'gw-threat-add']) ?>
            <div class="gw-field">
                <label class="form-label gw-field__label" for="watch-term">Term</label>
                <?= Html::activeTextInput($model, 'term', ['id' => 'watch-term', 'class' => 'form-control', 'maxlength' => 120, 'required' => true, 'placeholder' => 'For example: Jira']) ?>
            </div>
            <div class="gw-field">
                <label class="form-label gw-field__label" for="watch-category">Group</label>
                <?= Html::activeDropDownList($model, 'category', $formCategories, ['id' => 'watch-category', 'class' => 'form-select']) ?>
            </div>
            <?= Html::submitButton('<i class="bi bi-plus-lg"></i> Add', ['class' => 'btn btn-primary gw-btn']) ?>
        <?= Html::endForm() ?>
    </section>
<?php endif; ?>

<?php foreach ($categories as $category => $label): if (empty($groups[$category])) continue; ?>
    <section class="gw-panel gw-panel--flush">
        <div class="gw-panel__header gw-panel__header--flush">
            <h2 class="gw-panel__title"><?= Html::encode($label) ?> <span class="gw-panel__count"><?= count($groups[$category]) ?></span></h2>
        </div>
        <div class="table-responsive">
            <table class="table gw-table gw-threat-terms">
                <thead><tr><th scope="col">Term</th><th scope="col" class="gw-threat-col-aux">From</th><th scope="col" class="text-end" title="Stories mentioning it">Stories</th><th scope="col" class="gw-threat-col-aux">Status</th><?php if ($canManage): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($groups[$category] as $term): ?>
                    <tr<?= $term->is_enabled ? '' : ' class="gw-threat-terms__off"' ?>>
                        <td><?= Html::encode($term->term) ?></td>
                        <td class="gw-threat-col-aux"><?= Badge::render('threat-origin', $term->origin, $origins[$term->origin] ?? $term->origin) ?></td>
                        <td class="text-end gw-mono"><?= number_format($matches[$term->term] ?? 0) ?></td>
                        <td class="gw-threat-col-aux"><?= $term->is_enabled ? 'On' : '<span class="gw-muted">Off</span>' ?></td>
                        <?php if ($canManage): ?>
                            <td class="text-end gw-threat-terms__actions">
                                <?= Html::a($term->is_enabled ? 'Switch off' : 'Switch on', ['toggle', 'id' => $term->id], ['class' => 'btn btn-outline-secondary btn-sm', 'data-method' => 'post']) ?>
                                <?php if (!$term->getIsDerived()): ?>
                                    <?= Html::a('Remove', ['delete', 'id' => $term->id], ['class' => 'btn btn-outline-secondary btn-sm', 'data-method' => 'post', 'data-confirm' => sprintf('Remove %s from the watchlist?', $term->term)]) ?>
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
