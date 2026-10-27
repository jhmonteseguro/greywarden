<?php
/**
 * @var yii\web\View $this
 * @var string $chart rendered chart (or empty-state)
 * @var array $rows [technique, incidents, coverage{total, addressed, gaps}]
 * @var int $months
 * @var callable $incidentUrl
 * @var string $label catalogue version
 */

use app\modules\attack\widgets\TechniqueTags;
use yii\helpers\Html;

?>
<div class="row g-3 gw-attack-panel">
    <div class="col-lg-6">
        <h3 class="gw-attack-panel__heading">Incidents per tactic <span class="gw-muted">· last <?= $months ?> months</span></h3>
        <?= $chart ?>
    </div>
    <div class="col-lg-6">
        <h3 class="gw-attack-panel__heading">Top techniques <span class="gw-muted">· last <?= $months ?> months</span></h3>
        <?php if (!$rows): ?>
            <p class="gw-empty">Record techniques on incidents to see them here.</p>
        <?php else: ?>
            <ol class="gw-attack-top">
                <?php foreach ($rows as $row):
                    $c = $row['coverage'];
                    $tone = $c['total'] === 0 ? 'muted' : ($c['addressed'] === 0 ? 'danger' : ($c['gaps'] > 0 ? 'warning' : 'success')); ?>
                    <li class="gw-attack-top__item">
                        <?= TechniqueTags::tag($row['technique']) ?>
                        <span class="gw-attack-top__meta">
                            <?= Html::a($row['incidents'] . ' incident' . ($row['incidents'] === 1 ? '' : 's'), $incidentUrl($row['technique']), ['class' => 'gw-attack-top__count']) ?>
                            <span class="gw-attack-top__coverage gw-tone--<?= $tone ?>" title="Recommended ATT&CK mitigations approved or in place">
                                <i class="bi bi-shield-check" aria-hidden="true"></i> <?= $c['addressed'] ?>/<?= $c['total'] ?>
                            </span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <p class="gw-attack-panel__note"><?= Html::encode($label) ?> · shield = MITRE-recommended mitigations planned or in place (Control Register and Decision Log).</p>
    </div>
</div>
