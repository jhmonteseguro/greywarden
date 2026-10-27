<?php
/**
 * ASN report: announced prefixes, with the number of threat-feed listings inside each (IPv4).
 * @var yii\web\View $this
 * @var app\modules\intel\components\Report $report
 */

use app\modules\intel\components\Feeds;
use app\modules\intel\components\IpAddress;
use yii\helpers\Html;

$prefixes = $report->fact('prefixes', []);
$listed = $prefixes ? Feeds::countPerPrefix($prefixes) : [];
$rows = [];
foreach ($prefixes as $p) {
    $r = IpAddress::parseCidr($p);
    if ($r) {
        $rows[] = ['cidr' => $r['cidr'], 'version' => $r['version'], 'prefix' => $r['prefix'], 'start' => $r['start'], 'listed' => $listed[$r['cidr']] ?? 0];
    }
}
// Most-listed first, then by address
usort($rows, static fn($a, $b) => $b['listed'] <=> $a['listed'] ?: $a['version'] <=> $b['version'] ?: strcmp($a['start'], $b['start']));
$max = max(1, ...(array_column($rows, 'listed') ?: [1]));
$shown = array_slice($rows, 0, 500);
?>
<?php if (!$rows): ?>
    <div class="gw-empty-state"><i class="bi bi-diagram-2" aria-hidden="true"></i><p>No announced prefixes<?= $report->indicator->isPublic() ? '' : ' (special-purpose AS, not queried)' ?>.</p></div>
<?php else: ?>
    <section class="gw-panel gw-panel--flush">
        <div class="gw-panel__header gw-panel__header--flush">
            <h2 class="gw-panel__title"><i class="bi bi-diagram-2" aria-hidden="true"></i> Announced prefixes <span class="gw-panel__count"><?= count($rows) ?></span></h2>
            <div class="gw-panel__tools">
                <span class="gw-muted gw-intel-source__time">Listings counted for the first 400 prefixes; sorted by listings</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-gw-copy="<?= Html::encode(implode("\n", array_column($rows, 'cidr'))) ?>"><i class="bi bi-copy"></i> Copy all</button>
            </div>
        </div>
        <div class="table-responsive gw-intel-prefixes">
            <table class="table gw-coverage__table">
                <thead><tr><th scope="col">Prefix</th><th scope="col">Size</th><th scope="col">Feed listings</th><th scope="col" class="w-50"><span class="visually-hidden">Density</span></th></tr></thead>
                <tbody>
                <?php foreach ($shown as $row): ?>
                    <tr class="<?= $row['listed'] ? 'gw-intel-row--listed' : '' ?>">
                        <td class="gw-mono"><?= Html::a(Html::encode($row['cidr']), ['ip', 'ip' => explode('/', $row['cidr'])[0]], ['class' => 'gw-intel-pivot']) ?></td>
                        <td class="gw-muted gw-mono">/<?= $row['prefix'] ?> · <?= $row['version'] === 4 ? number_format(IpAddress::prefixSize(4, $row['prefix'])) . ' addr' : 'IPv6' ?></td>
                        <td class="gw-mono"><?= $row['listed'] ? number_format($row['listed']) : '<span class="gw-muted">0</span>' ?></td>
                        <td><span class="gw-intel-meter"><span style="width: <?= round(100 * $row['listed'] / $max) ?>%"></span></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($rows) > count($shown)): ?>
            <p class="gw-muted gw-intel-source__summary px-3 pb-3 mb-0"><?= count($rows) - count($shown) ?> more prefixes not shown (use “Copy all”).</p>
        <?php endif; ?>
    </section>
<?php endif; ?>
