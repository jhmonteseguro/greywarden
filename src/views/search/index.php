<?php
/**
 * @var yii\web\View $this
 * @var string $q
 * @var array $results
 * @var app\modules\intel\components\Indicator|null $investigate IP / ASN typed into the search
 */

use app\helpers\Badge;
use app\modules\attack\components\AttackText;
use app\modules\attack\models\AttackMitigation;
use app\modules\attack\models\AttackTechnique;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\control\models\FrameworkRequirement;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\intel\models\BlocklistEntry;
use app\modules\inventory\models\Device;
use app\modules\report\models\Report;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;
use yii\helpers\Html;

$this->title = $q === '' ? 'Search' : 'Search results';
$this->params['breadcrumbs'][] = 'Search';
$total = array_sum(array_map(static fn($g) => count($g['items']), $results));
$this->params['subtitle'] = $q === '' ? 'Enter a reference, title, hostname, serial number, IP address, cloud resource ID or ATT&CK id (e.g. T1566).'
    : Html::encode(sprintf('%d result(s) for “%s”', $total, $q));
?>
<form class="gw-search-page" action="" method="get" role="search">
    <div class="input-group">
        <input type="search" name="q" class="form-control" value="<?= Html::encode($q) ?>" placeholder="Search…" aria-label="Search">
        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
    </div>
</form>

<?php if ($investigate): ?>
    <a class="gw-intel-cta" href="<?= Html::encode(\yii\helpers\Url::to($investigate->url())) ?>">
        <span class="gw-intel-cta__icon" aria-hidden="true"><i class="bi bi-radar"></i></span>
        <span class="gw-intel-cta__text">
            <strong>Investigate <span class="gw-mono"><?= Html::encode($investigate->value) ?></span></strong>
            <span>Run a threat intel lookup: reputation, threat feeds, WHOIS, routing<?= $investigate->isIp() ? ', exposure and blocklists' : ' and abuse density' ?>.</span>
        </span>
        <i class="bi bi-arrow-right gw-intel-cta__go" aria-hidden="true"></i>
    </a>
<?php endif; ?>

<?php foreach ($results as $key => $group): ?>
    <section class="gw-panel gw-search-group gw-search-group--<?= $key ?>">
        <h2 class="gw-panel__title"><i class="bi bi-<?= $group['icon'] ?>"></i> <?= Html::encode($group['label']) ?>
            <span class="gw-panel__count"><?= count($group['items']) ?></span></h2>
        <?php if (!$group['items']): ?>
            <p class="gw-empty">No matches.</p>
        <?php else: ?>
            <ul class="gw-search-results">
                <?php foreach ($group['items'] as $item): ?>
                    <li class="gw-search-result">
                        <?= Html::a(Html::encode($item->getReferenceValue()), $item->getViewUrl(), ['class' => 'gw-ref']) ?>
                        <span class="gw-search-result__title"><?= Html::encode($item->getTitleValue()) ?></span>
                        <?php
                        if ($item instanceof AttackTechnique || $item instanceof AttackMitigation) {
                            echo '<span class="gw-muted">' . Html::encode(AttackText::summary($item->description, 120)) . '</span>';
                            if ($item->is_deprecated) {
                                echo ' ' . Badge::flag('deprecated', 'Deprecated');
                            }
                        } elseif ($item instanceof Risk) {
                            echo Badge::status('risk-status', $item->status, Risk::statusOptions()) . ' ' . Badge::score($item->getCurrentScore());
                        } elseif ($item instanceof Control) {
                            echo Badge::status('control-status', $item->status, Control::statusOptions()) . ' ' . Badge::effectiveness($item->effectiveness);
                        } elseif ($item instanceof FrameworkRequirement) {
                            echo '<span class="gw-muted">' . Html::encode($item->getFrameworkLabel() . ' · ' . $item->group_name) . '</span>'
                                . ($item->applicable ? '' : ' ' . Badge::flag('archived', 'Not applicable'));
                        } elseif ($item instanceof Report) {
                            echo Badge::status('report-status', $item->status, Report::statusOptions())
                                . ($item->rag ? ' ' . Badge::render('rag', $item->rag, $item->getRagLabel()) : '');
                        } elseif ($item instanceof Decision) {
                            echo Badge::status('decision-status', $item->status, Decision::statusOptions());
                        } elseif ($item instanceof Incident) {
                            echo Badge::severity($item->severity) . ' ' . Badge::status('incident-status', $item->status, Incident::statusOptions());
                        } elseif ($item instanceof BlocklistEntry) {
                            echo Badge::blockState($item->getState()) . ' <span class="gw-muted">' . Html::encode(\yii\helpers\StringHelper::truncate($item->reason, 90)) . '</span>';
                        } elseif ($item instanceof Device) {
                            echo '<span class="gw-muted">' . Html::encode(trim($item->getTypeLabel() . ' · ' . $item->serial_number, ' ·')) . '</span> '
                                . Badge::status('device-status', $item->status, Device::statusOptions());
                        } elseif ($item instanceof CloudAsset) {
                            echo '<span class="gw-muted">' . Html::encode(trim($item->getTypeLabel() . ' · ' . ($item->account->name ?? ''), ' ·')) . '</span> '
                                . Badge::status('cloud-status', $item->status, CloudAsset::statusOptions());
                        } elseif ($item instanceof CloudAccount) {
                            echo '<span class="gw-muted">' . Html::encode($item->getProviderSummary()) . '</span> '
                                . Badge::status('cloud-status', $item->status, CloudAccount::statusOptions());
                        } elseif ($item instanceof Supplier) {
                            echo Badge::tier($item->tier) . ' ' . Badge::status('supplier-status', $item->status, Supplier::statusOptions());
                        }
                        if ($item->getIsArchived() && !$item instanceof BlocklistEntry) {
                            echo ' ' . Badge::flag('archived', 'Archived');
                        }
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
