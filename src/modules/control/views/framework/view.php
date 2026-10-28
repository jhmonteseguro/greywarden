<?php
/**
 * Statement of Applicability (ISO 27001) / readiness (Cyber Essentials, SOC 2) / profile (NIST CSF).
 *
 * @var yii\web\View $this
 * @var string $framework
 * @var array $rows FrameworkCoverage::rows(), possibly filtered by $state
 * @var array $summary FrameworkCoverage::summary() of all rows
 * @var string $state current state filter ('' = all)
 * @var array|null $devices Cyber Essentials device readiness (see FrameworkController::deviceReadiness())
 * @var string[]|null $soc2Scope SOC 2 Trust Services categories in scope
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\components\FrameworkCoverage;
use app\modules\control\models\Control;
use yii\helpers\Html;
use yii\helpers\Url;

$label = FrameworkCatalogue::label($framework);
$isCe = $framework === FrameworkCatalogue::CYBER_ESSENTIALS;
$this->title = FrameworkCatalogue::title($framework);
$this->params['breadcrumbs'][] = ['label' => 'Control Register', 'url' => ['/control/control/index']];
$this->params['breadcrumbs'][] = ['label' => 'Frameworks', 'url' => ['index']];
$this->params['breadcrumbs'][] = FrameworkCatalogue::shortLabel($framework);
$this->params['subtitle'] = Html::encode(FrameworkCatalogue::version($framework));
$this->params['actions'] = Html::a('<i class="bi bi-shield-shaded"></i> Control Register', ['/control/control/index'], ['class' => 'btn btn-outline-secondary gw-btn']);
$filters = ['' => 'All'] + FrameworkCoverage::stateLabels();
?>
<div class="row g-4">
    <div class="col-xl-<?= $devices !== null ? 7 : 12 ?>">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><?= Html::encode($label) ?></h2>
            <?= $this->render('_progress', ['framework' => $framework, 'summary' => $summary]) ?>
            <?php if ($isCe): ?>
                <p class="gw-muted gw-small mb-0">Cyber Essentials is pass / fail: every requirement must be met across the whole scope.
                    Requirements are paraphrased; check them against the current NCSC requirements document before an assessment.</p>
            <?php elseif ($soc2Scope !== null): ?>
                <p class="gw-muted gw-small">Trust Services categories in scope:
                    <strong><?= Html::encode(implode(', ', array_intersect_key(FrameworkCatalogue::soc2Categories(), array_flip($soc2Scope)))) ?></strong>.
                    <?= Rbac::can(Rbac::PERM_SETTINGS) ? Html::a('Change in Settings', ['/admin/settings/index', '#' => 'compliance']) : 'An administrator can change them in Settings.' ?></p>
                <p class="gw-muted gw-small mb-0">Criteria are paraphrased; see the AICPA 2017 Trust Services Criteria for the criteria text and points of focus.
                    Criteria marked not applicable need a justification.</p>
            <?php elseif ($framework === FrameworkCatalogue::NIST_CSF): ?>
                <p class="gw-muted gw-small mb-0">Your current profile: how far the controls achieve each CSF 2.0 outcome. Outcomes outside your target profile
                    can be marked not applicable with a justification. See NIST CSWP 29 and its implementation examples for guidance.</p>
            <?php else: ?>
                <p class="gw-muted gw-small mb-0">Requirements marked not applicable need a justification. Control titles only: see ISO/IEC 27001:2022 and 27002:2022 for the control text.</p>
            <?php endif; ?>
        </section>
    </div>
    <?php if ($devices !== null): ?>
        <div class="col-xl-5">
            <section class="gw-panel gw-panel--flush">
                <h2 class="gw-panel__title"><i class="bi bi-pc-display" aria-hidden="true"></i> Device checks in scope
                    <span class="gw-panel__count"><?= (int) $devices['total'] ?> in service</span></h2>
                <?php if (!$devices['checks']): ?>
                    <p class="gw-empty">No device check in force is mapped to a Cyber Essentials requirement.</p>
                <?php else: ?>
                    <table class="table gw-coverage__table">
                        <thead><tr><th scope="col">Check</th><th scope="col" class="text-end">Pass</th><th scope="col" class="text-end">Fail</th><th scope="col" class="text-end">Not assessed</th></tr></thead>
                        <tbody>
                        <?php foreach ($devices['checks'] as $id => $c): ?>
                            <tr>
                                <td><?= Html::a(Html::encode($c['check']->reference), ['/control/control/view', 'id' => $id], ['class' => 'gw-ref']) ?>
                                    <?= Html::encode($c['check']->title) ?>
                                    <div class="gw-muted gw-small gw-mono"><?= Html::encode(implode(', ', $c['requirements'])) ?></div></td>
                                <?php foreach (['pass', 'fail', 'unknown'] as $k): ?>
                                    <td class="text-end gw-mono"><?= $c[$k]
                                        ? Html::a($c[$k], ['/inventory/device/index', 'filter' => 'active', 'control' => $id, 'result' => $k], ['class' => $k === 'pass' ? null : 'gw-text-warning'])
                                        : '<span class="gw-null">0</span>' ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</div>

<nav class="gw-quickfilters gw-quickfilters--tabs" aria-label="Filter by status">
    <?php foreach ($filters as $key => $text): ?>
        <?= Html::a(Html::encode($text) . ($key !== '' ? ' <span class="gw-quickfilter__count">' . (int) $summary[$key] . '</span>' : ''),
            Url::current(['state' => $key ?: null]), ['class' => 'gw-quickfilter' . ($state === $key ? ' is-active' : ''), 'aria-current' => $state === $key ? 'page' : null]) ?>
    <?php endforeach; ?>
</nav>

<section class="gw-panel gw-panel--flush">
    <?php if (!$rows): ?>
        <p class="gw-empty">No requirements in this state.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table gw-coverage__table gw-soa">
            <thead>
            <tr>
                <th scope="col">ID</th>
                <th scope="col">Requirement</th>
                <th scope="col"><?= $isCe ? 'In scope' : 'Applicable' ?></th>
                <th scope="col">Implemented by</th>
                <th scope="col">Status</th>
            </tr>
            </thead>
            <?php foreach (FrameworkCoverage::byGroup($rows) as $group => $groupRows): ?>
                <tbody>
                <tr class="gw-soa__group"><th colspan="5" scope="rowgroup"><?= Html::encode($group) ?></th></tr>
                <?php foreach ($groupRows as $id => $row): $r = $row['requirement']; ?>
                    <tr class="gw-coverage__row gw-soa__row gw-soa__row--<?= Html::encode($row['state']) ?>">
                        <td class="gw-mono"><?= Html::a(Html::encode($r->external_id), $r->getViewUrl(), ['class' => 'gw-ref']) ?></td>
                        <td>
                            <?= Html::a(Html::encode($r->title), $r->getViewUrl(), ['class' => 'gw-soa__title']) ?>
                            <?php if ($r->description): ?><div class="gw-coverage__desc"><?= Html::encode($r->description) ?></div><?php endif; ?>
                            <?php if ($r->justification): ?><div class="gw-soa__justification"><i class="bi bi-chat-left-text" aria-hidden="true"></i> <?= Html::encode($r->justification) ?></div><?php endif; ?>
                            <?php if ($r->partial_coverage && $r->applicable): ?><div class="gw-soa__justification"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <span class="visually-hidden">Coverage gap:</span> <?= Html::encode((string) $r->coverage_gap) ?></div><?php endif; ?>
                        </td>
                        <td><?= Badge::bool($r->applicable) ?></td>
                        <td class="gw-soa__controls">
                            <?php if (!$row['controls']): ?>
                                <span class="gw-null">&mdash;</span>
                            <?php else: foreach ($row['controls'] as $c): ?>
                                <div class="gw-soa__control">
                                    <?= Html::a(Html::encode($c->reference), $c->getViewUrl(), ['class' => 'gw-ref', 'title' => $c->title]) ?>
                                    <span class="gw-soa__control-title"><?= Html::encode($c->title) ?></span>
                                    <?= Badge::status('control-status', $c->status, Control::statusOptions()) ?>
                                    <?= $c->status === Control::STATUS_IMPLEMENTED ? Badge::effectiveness($c->effectiveness) : '' ?>
                                </div>
                            <?php endforeach; endif; ?>
                        </td>
                        <td><?= Badge::frameworkState($row['state'], $r->partial_coverage && $r->applicable ? 'Controls cover only part of this requirement' : null) ?>
                            <?= $row['unverified'] ? Badge::flag('unverified', 'Not tested', 'Met by controls whose effectiveness has not been tested yet') : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
</section>
