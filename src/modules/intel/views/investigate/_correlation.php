<?php
/**
 * What Greywarden knows locally: blocklist entries, own assets, incidents mentioning the indicator.
 * @var yii\web\View $this
 * @var app\modules\intel\components\Indicator $indicator
 * @var app\modules\intel\components\LocalContext $local
 * @var app\modules\intel\models\IntelIndicator $model
 */

use app\helpers\Badge;
use app\modules\incident\models\Incident;
use app\modules\intel\models\BlocklistEntry;
use app\modules\inventory\models\Device;
use app\widgets\LinkedRecords;
use yii\helpers\Html;
?>
<div class="row g-4">
    <div class="col-lg-4"><?= LinkedRecords::widget([
        'title' => 'Blocklist', 'icon' => 'slash-circle', 'models' => $local->blocks,
        'emptyText' => $indicator->isIp() ? 'Not covered by any blocklist entry (IP, network or origin AS).' : 'This AS is not blocked.',
        'extra' => static fn(BlocklistEntry $b) => Badge::blockState($b->getState()) . ' ' . Badge::severity($b->severity)
            . ($b->value !== $indicator->value ? ' <span class="gw-muted">via ' . Html::encode($b->getTypeLabel()) . '</span>' : ''),
    ]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget([
        'title' => 'Incidents mentioning it', 'icon' => 'fire', 'models' => $local->incidents,
        'emptyText' => 'No incident description or timeline mentions ' . $indicator->value . '.',
        'extra' => static fn(Incident $i) => Badge::severity($i->severity) . ' ' . Badge::status('incident-status', $i->status, Incident::statusOptions()),
    ]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget([
        'title' => 'Your devices', 'icon' => 'pc-display', 'models' => $local->devices,
        'emptyText' => $indicator->isIp() ? 'No device in the inventory has this address.' : 'Device matching is by IP address.',
        'extra' => static fn(Device $d) => Badge::criticality($d->criticality),
    ]) ?></div>
</div>
