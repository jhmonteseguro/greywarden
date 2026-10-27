<?php
/**
 * @var yii\web\View $this
 * @var app\modules\attack\models\AttackMitigation[] $mitigations ranked by relevance
 * @var array<int,array{techniques:int, observed:int, modelled:int}> $stats
 * @var array<int,array{state:string, controls:array, decisions:array}> $adoption
 * @var array|null $release
 */

use app\modules\attack\components\AttackText;
use app\modules\attack\components\Catalogue;
use app\modules\attack\components\Coverage;
use app\modules\attack\widgets\MitigationCoverage;
use yii\helpers\Html;

$this->title = 'ATT&CK mitigations';
$this->params['breadcrumbs'][] = ['label' => 'ATT&CK', 'url' => ['/attack/technique/index']];
$this->params['breadcrumbs'][] = 'Mitigations';
$this->params['subtitle'] = Html::encode(Catalogue::label()) . ' · ranked by the techniques they address that were observed in incidents or mapped to open risks.';
$this->params['actions'] = Html::a('<i class="bi bi-crosshair"></i> Matrix', ['/attack/technique/index'], ['class' => 'btn btn-outline-secondary gw-btn']);

$gaps = count(array_filter($mitigations, static fn($m) => ($stats[$m->id]['observed'] || $stats[$m->id]['modelled'])
    && !in_array($m->external_id, Coverage::NOT_ACTIONABLE, true)
    && !in_array($adoption[$m->id]['state'] ?? Coverage::STATE_GAP, [Coverage::STATE_IMPLEMENTED, Coverage::STATE_APPROVED], true)));
?>
<?php if ($gaps): ?>
    <div class="alert alert-warning gw-callout" role="status">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
        <strong><?= $gaps ?></strong> mitigation(s) address techniques seen in your incidents or risks but have no control or approved decision.
    </div>
<?php endif; ?>

<section class="gw-panel gw-panel--flush">
    <div class="table-responsive">
        <table class="table gw-coverage__table gw-mitigation-table">
            <thead>
            <tr>
                <th scope="col">Mitigation</th>
                <th scope="col" class="text-end" title="Techniques MITRE maps this mitigation to">Techniques</th>
                <th scope="col" class="text-end" title="Of those, techniques recorded on incidents">Observed</th>
                <th scope="col" class="text-end" title="Of those, techniques mapped to open risks">In risks</th>
                <th scope="col">Status</th>
                <th scope="col">Controls / decisions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($mitigations as $m):
                $s = $stats[$m->id];
                $a = $adoption[$m->id] ?? ['state' => Coverage::STATE_GAP, 'controls' => [], 'decisions' => []];
                $relevant = ($s['observed'] || $s['modelled']) && !in_array($m->external_id, Coverage::NOT_ACTIONABLE, true); ?>
                <tr class="gw-coverage__row gw-coverage__row--<?= Html::encode($a['state']) ?><?= $relevant ? '' : ' is-quiet' ?>">
                    <td>
                        <?= Html::a('<span class="gw-attack-tag__id">' . Html::encode($m->external_id) . '</span> ' . Html::encode($m->name), $m->getViewUrl(), ['class' => 'gw-coverage__mitigation']) ?>
                        <div class="gw-coverage__desc"><?= Html::encode(AttackText::summary($m->description, 160)) ?></div>
                    </td>
                    <td class="text-end gw-mono"><?= $s['techniques'] ?></td>
                    <td class="text-end gw-mono"><?= $s['observed'] ?: '<span class="gw-null">0</span>' ?></td>
                    <td class="text-end gw-mono"><?= $s['modelled'] ?: '<span class="gw-null">0</span>' ?></td>
                    <td><?= in_array($m->external_id, Coverage::NOT_ACTIONABLE, true) ? '<span class="gw-null">n/a</span>' : MitigationCoverage::stateBadge($a['state']) ?></td>
                    <td><?= MitigationCoverage::recordLinks($a) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?= $this->render('../partials/_attribution', ['release' => $release]) ?>
