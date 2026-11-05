<?php
/**
 * @var yii\web\View $this
 * @var array $rows see Coverage::mitigationsFor()
 * @var array $summary total / addressed / gaps
 * @var int $maxTechniques techniques to list on a row (0 = all)
 */

use app\modules\attack\widgets\MitigationCoverage;
use yii\helpers\Html;

?>
<div class="gw-coverage">
    <p class="gw-coverage__summary">
        <strong><?= $summary['addressed'] ?></strong> of <strong><?= $summary['total'] ?></strong> recommended mitigations are planned or in place
        <?php if ($summary['gaps']): ?><span class="gw-coverage__gaps">· <?= $summary['gaps'] ?> without a control or approved decision</span><?php endif; ?>
    </p>
    <div class="table-responsive">
        <table class="table gw-coverage__table">
            <thead>
            <tr><th scope="col">Mitigation</th><th scope="col">Addresses</th><th scope="col">Status</th><th scope="col">Controls / decisions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): $m = $row['mitigation']; ?>
                <tr class="gw-coverage__row gw-coverage__row--<?= Html::encode($row['state']) ?>">
                    <td>
                        <?= Html::a('<span class="gw-attack-tag__id">' . Html::encode($m->external_id) . '</span> ' . Html::encode($m->name), $m->getViewUrl(), ['class' => 'gw-coverage__mitigation']) ?>
                    </td>
                    <?php $shown = $maxTechniques ? array_slice($row['techniques'], 0, $maxTechniques) : $row['techniques']; $more = count($row['techniques']) - count($shown); ?>
                    <td class="gw-coverage__techniques"><span class="gw-mono"<?= $more ? ' title="' . Html::encode(implode(', ', $row['techniques'])) . '"' : '' ?>><?= Html::encode(implode(', ', $shown)) ?><?= $more ? ' +' . $more : '' ?></span></td>
                    <td><?= MitigationCoverage::stateBadge($row['state']) ?></td>
                    <td><?= MitigationCoverage::recordLinks($row) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
