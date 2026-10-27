<?php
/**
 * ATT&CK matrix: one column per tactic, one cell per (parent) technique,
 * shaded by the number of incidents / open risks mapped to it or its sub-techniques.
 *
 * @var yii\web\View $this
 * @var string $layer incidents|risks
 * @var string $show all|mapped
 * @var app\modules\attack\models\AttackTactic[] $tactics
 * @var array<int, app\modules\attack\models\AttackTechnique[]> $columns tactic id => techniques
 * @var array<int,int> $counts technique id => record count (rolled up)
 * @var array<int,int> $subCounts technique id => sub-technique count
 * @var array|null $release
 */

use app\modules\attack\components\AttackText;
use app\modules\attack\components\Catalogue;
use app\modules\attack\components\Coverage;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'ATT&CK matrix';
$this->params['breadcrumbs'][] = 'ATT&CK';
$this->params['subtitle'] = Html::encode(Catalogue::label()) . ' · techniques shaded by the number of '
    . ($layer === Coverage::LAYER_RISKS ? 'open risks mapped to them' : 'incidents they were observed in') . '.';
$this->params['actions'] = Html::a('<i class="bi bi-shield-check"></i> Mitigations', ['/attack/mitigation/index'], ['class' => 'btn btn-outline-secondary gw-btn']);

$noun = $layer === Coverage::LAYER_RISKS ? 'risk' : 'incident';
$heat = static fn(int $n) => $n === 0 ? 0 : ($n === 1 ? 1 : ($n === 2 ? 2 : ($n <= 4 ? 3 : 4)));
$mappedTotal = count($counts);
?>
<div class="gw-toolbar gw-matrix-toolbar" data-layer="<?= Html::encode($layer) ?>">
    <div class="gw-segmented" role="group" aria-label="Matrix layer">
        <?php foreach (Coverage::layerLabels() as $key => $label): ?>
            <?= Html::a(Html::encode($label), Url::current(['layer' => $key]), [
                'class' => 'gw-segmented__item' . ($layer === $key ? ' is-active' : ''),
                'aria-pressed' => $layer === $key ? 'true' : 'false',
            ]) ?>
        <?php endforeach; ?>
    </div>
    <div class="gw-segmented" role="group" aria-label="Techniques shown">
        <?php foreach (['all' => 'Full matrix', 'mapped' => 'Mapped only (' . $mappedTotal . ')'] as $key => $label): ?>
            <?= Html::a(Html::encode($label), Url::current(['show' => $key]), [
                'class' => 'gw-segmented__item' . ($show === $key ? ' is-active' : ''),
                'aria-pressed' => $show === $key ? 'true' : 'false',
            ]) ?>
        <?php endforeach; ?>
    </div>
    <ul class="gw-matrix__legend" aria-label="Legend">
        <?php foreach ([1 => '1', 2 => '2', 3 => '3–4', 4 => '5+'] as $level => $label): ?>
            <li class="gw-matrix__legend-item"><span class="gw-matrix__swatch gw-matrix__cell--heat-<?= $level ?>"></span><?= $label ?></li>
        <?php endforeach; ?>
        <li class="gw-matrix__legend-item gw-muted"><?= Html::encode($noun) ?>s</li>
    </ul>
</div>

<?php if ($show === 'mapped' && !$mappedTotal): ?>
    <div class="gw-empty-state">
        <i class="bi bi-crosshair" aria-hidden="true"></i>
        <p>No techniques are mapped to <?= $layer === Coverage::LAYER_RISKS ? 'open risks' : 'incidents' ?> yet.
            Add them from the <?= $layer === Coverage::LAYER_RISKS ? Html::a('Risk Register', ['/risk/risk/index']) : Html::a('Incident Log', ['/incident/incident/index']) ?>.</p>
    </div>
<?php else: ?>
<div class="gw-matrix gw-matrix--<?= Html::encode($layer) ?>" data-layer="<?= Html::encode($layer) ?>">
    <div class="gw-matrix__grid" style="--gw-matrix-cols: <?= count($tactics) ?>">
        <?php foreach ($tactics as $tactic):
            $list = $columns[$tactic->id] ?? [];
            $hits = count(array_filter($list, static fn($t) => !empty($counts[$t->id]))); ?>
            <section class="gw-matrix__col<?= $hits ? ' has-hits' : '' ?>" id="<?= Html::encode($tactic->external_id) ?>" aria-labelledby="<?= Html::encode($tactic->external_id) ?>-h">
                <header class="gw-matrix__head" title="<?= Html::encode(AttackText::summary($tactic->description, 200)) ?>">
                    <h2 class="gw-matrix__tactic" id="<?= Html::encode($tactic->external_id) ?>-h"><?= Html::encode($tactic->name) ?></h2>
                    <span class="gw-matrix__meta"><span class="gw-mono"><?= Html::encode($tactic->external_id) ?></span> · <?= count($list) ?></span>
                    <?php if ($hits): ?><span class="gw-matrix__hits" title="<?= $hits ?> technique(s) with <?= $noun ?>s"><?= $hits ?></span><?php endif; ?>
                </header>
                <ol class="gw-matrix__list">
                    <?php foreach ($list as $t):
                        $n = $counts[$t->id] ?? 0;
                        $subs = $subCounts[$t->id] ?? 0; ?>
                        <li>
                            <?= Html::a(
                                '<span class="gw-matrix__name">' . Html::encode($t->name) . '</span>'
                                . '<span class="gw-matrix__foot"><span class="gw-matrix__id">' . Html::encode($t->external_id) . '</span>'
                                . ($subs ? '<span class="gw-matrix__subs" title="' . $subs . ' sub-techniques">+' . $subs . '</span>' : '')
                                . ($n ? '<span class="gw-matrix__count">' . $n . '</span>' : '') . '</span>',
                                $t->getViewUrl(),
                                [
                                    'class' => 'gw-matrix__cell gw-matrix__cell--heat-' . $heat($n),
                                    'aria-label' => sprintf('%s %s: %d %s(s)', $t->external_id, $t->name, $n, $noun),
                                ]
                            ) ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?= $this->render('../partials/_attribution', ['release' => $release]) ?>
