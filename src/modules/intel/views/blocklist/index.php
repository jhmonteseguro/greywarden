<?php
/**
 * Blocklist: separate pages for IP addresses & networks and for ASNs (?kind=asn).
 *
 * @var yii\web\View $this
 * @var app\modules\intel\models\BlocklistSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var array $counts kind => filter => count
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\User;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\BlocklistSearch;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;
use yii\helpers\StringHelper;
use yii\helpers\Url;

$isAsn = $searchModel->kind === 'asn';
$this->title = $isAsn ? 'Blocked ASNs' : 'Blocked IP addresses';
$this->params['breadcrumbs'][] = ['label' => 'Blocklist', 'url' => ['index']];
$this->params['breadcrumbs'][] = $isAsn ? 'ASNs' : 'IP addresses & networks';
$this->params['subtitle'] = sprintf('%d in force · %d expiring within 7 days', $counts[$searchModel->kind]['active'], $counts[$searchModel->kind]['expiring']);
$this->params['actions'] = Rbac::canManage() ? Html::a('<i class="bi bi-slash-circle"></i> ' . ($isAsn ? 'Block an ASN' : 'Block an address'), ['create'], ['class' => 'btn btn-danger gw-btn gw-btn--create']) : '';
$fmt = Yii::$app->formatter;
?>
<nav class="gw-intel-kinds" aria-label="Blocklist type">
    <?php foreach (BlocklistSearch::KINDS as $kind => $label): ?>
        <a class="gw-intel-kind-tab<?= $searchModel->kind === $kind ? ' is-active' : '' ?>" href="<?= Html::encode(Url::to(['index', 'kind' => $kind])) ?>" <?= $searchModel->kind === $kind ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-<?= $kind === 'asn' ? 'diagram-3' : 'router' ?>" aria-hidden="true"></i>
            <span class="gw-intel-kind-tab__label"><?= Html::encode($label) ?></span>
            <span class="gw-intel-kind-tab__count"><?= $counts[$kind]['active'] ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?= QuickFilters::widget([
    'filters' => BlocklistSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'counts' => $counts[$searchModel->kind],
    'placeholder' => $isAsn ? 'Search ASN, reason…' : 'Search address, network, reason…',
]) ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'emptyText' => $isAsn ? 'No ASNs match. Block one from an ASN investigation or with “Block an ASN”.' : 'No addresses match. Block one from an investigation or with “Block an address”.',
    'columns' => array_values(array_filter([
        ['class' => ReferenceColumn::class, 'attribute' => 'reference'],
        [
            'attribute' => 'value',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-title'],
            'value' => static function (BlocklistEntry $m) {
                $url = $m->getIndicatorUrl();
                $html = $url ? Html::a(Html::encode($m->value), $url, ['class' => 'gw-intel-ioc', 'title' => 'Investigate']) : Html::encode($m->value);
                if ($m->cloudflare_added_at) {
                    $html .= ' <i class="bi bi-cloud-check gw-intel-cf" title="In the Cloudflare rule" aria-hidden="true"></i><span class="visually-hidden">(in Cloudflare)</span>';
                }
                return $html . '<div class="gw-intel-reason">' . Html::encode(StringHelper::truncate($m->reason, 110)) . '</div>';
            },
        ],
        $isAsn ? null : [
            'attribute' => 'indicator_type',
            'format' => 'raw',
            'value' => static fn(BlocklistEntry $m) => Badge::render('block-type', $m->indicator_type, $m->indicator_type === 'cidr' ? 'Network' : 'Address'),
            'filter' => [BlocklistEntry::TYPE_IP => 'Address', BlocklistEntry::TYPE_CIDR => 'Network'],
        ],
        [
            'attribute' => 'severity',
            'format' => 'raw',
            'value' => static fn(BlocklistEntry $m) => Badge::severity($m->severity),
            'filter' => BlocklistEntry::severityOptions(),
        ],
        [
            'attribute' => 'direction',
            'format' => 'raw',
            'value' => static fn(BlocklistEntry $m) => '<span class="gw-intel-direction gw-intel-direction--' . $m->direction . '"><i class="bi bi-' . ['both' => 'arrow-left-right', 'inbound' => 'box-arrow-in-down-right', 'outbound' => 'box-arrow-up-right'][$m->direction] . '" aria-hidden="true"></i> ' . Html::encode(['both' => 'Both', 'inbound' => 'Inbound', 'outbound' => 'Outbound'][$m->direction]) . '</span>',
            'filter' => BlocklistEntry::directionOptions(),
        ],
        [
            'label' => 'Incident',
            'format' => 'raw',
            'value' => static fn(BlocklistEntry $m) => $m->incident ? Html::a(Html::encode($m->incident->reference), $m->incident->getViewUrl(), ['class' => 'gw-ref', 'title' => $m->incident->title]) : '',
        ],
        [
            'attribute' => 'created_by',
            'format' => 'raw',
            'value' => static fn(BlocklistEntry $m) => Html::encode($m->creator->full_name ?? '—') . '<div class="gw-muted gw-intel-when">' . $fmt->asDatetime($m->created_at) . '</div>',
            'filter' => User::options(),
        ],
        [
            'attribute' => 'expires_at',
            'format' => 'raw',
            'filter' => false,
            'value' => static function (BlocklistEntry $m) use ($fmt) {
                if ($m->getIsArchived()) {
                    return Badge::blockState('unblocked') . '<div class="gw-muted gw-intel-when">' . $fmt->asDatetime($m->archived_at) . '</div>';
                }
                if (!$m->expires_at) {
                    return '<span class="gw-muted">Permanent</span>';
                }
                $soon = strtotime($m->expires_at) - time() < 7 * 86400;
                return ($m->getIsExpired() ? Badge::blockState('expired') : ($soon ? Badge::flag('expiring', 'Expiring') : ''))
                    . '<div class="gw-intel-when' . ($soon ? '' : ' gw-muted') . '">' . $fmt->asDatetime($m->expires_at) . '</div>';
            },
        ],
        ['class' => ActionColumn::class],
    ])),
]) ?>
