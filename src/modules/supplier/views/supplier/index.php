<?php
/**
 * @var yii\web\View $this
 * @var app\modules\supplier\models\SupplierSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\Lookup;
use app\models\User;
use app\modules\inventory\components\DevicePosture;
use app\modules\supplier\components\SupplierChecks;
use app\modules\supplier\models\Supplier;
use app\modules\supplier\models\SupplierSearch;
use app\widgets\grid\ActionColumn;
use app\widgets\grid\ReferenceColumn;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Supplier Register';
$this->params['breadcrumbs'][] = 'Supplier Register';
$this->params['actions'] = Rbac::canManage() ? Html::a('<i class="bi bi-plus-lg"></i> Add supplier', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '';
$fmt = Yii::$app->formatter;
?>
<?= QuickFilters::widget([
    'filters' => SupplierSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'placeholder' => 'Search reference, name, website, services…',
]) ?>
<?php if ($searchModel->control): $check = SupplierChecks::find((int) $searchModel->control); ?>
    <p class="gw-active-filter">Supplier check: <strong><?= Html::encode($check ? $check->reference . ' ' . $check->title : 'not in force') ?></strong>
        · <?= Html::encode(DevicePosture::resultLabels()[$searchModel->result] ?? $searchModel->result) ?>
        <?= Html::a('clear', Url::current(['control' => null, 'result' => null])) ?></p>
<?php endif; ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'columns' => [
        ['class' => ReferenceColumn::class, 'attribute' => 'reference'],
        [
            'attribute' => 'name',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-title'],
            'value' => static fn(Supplier $m) => Html::a(Html::encode($m->name), $m->getViewUrl(), ['class' => 'gw-title-link'])
                . ($m->services ? '<div class="gw-muted gw-small">' . Html::encode(mb_strimwidth($m->services, 0, 90, '…')) . '</div>' : ''),
        ],
        [
            'attribute' => 'tier',
            'format' => 'raw',
            'value' => static fn(Supplier $m) => Badge::tier($m->tier, $m->getTierExplanation()),
            'filter' => array_map(static fn($t) => 'Tier ' . $t, array_combine(Supplier::TIERS, Supplier::TIERS)),
        ],
        ['attribute' => 'category_id', 'value' => static fn(Supplier $m) => $m->category->name ?? null, 'filter' => Lookup::allOptions(Lookup::TYPE_SUPPLIER_CATEGORY)],
        ['attribute' => 'owner_id', 'value' => static fn(Supplier $m) => $m->owner->full_name ?? null, 'filter' => User::options()],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(Supplier $m) => Badge::status('supplier-status', $m->status, Supplier::statusOptions()),
            'filter' => Supplier::statusOptions(),
        ],
        [
            'attribute' => 'next_review_date',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-mono'],
            'value' => static fn(Supplier $m) => $m->next_review_date
                ? $fmt->asDate($m->next_review_date) . ($m->isReviewOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '')
                : null,
        ],
        [
            'label' => 'Assurance',
            'format' => 'raw',
            'value' => static function (Supplier $m) use ($fmt) {
                $due = $m->getAssuranceDue();
                if ($due) {
                    $a = $due[0];
                    return Badge::render('assurance', $a->isExpired() ? 'expired' : 'expiring', $a->isExpired() ? 'Expired' : 'Expiring',
                        ['title' => $a->getTypeLabel() . ' · ' . $fmt->asDate($a->expires_on)]);
                }
                return $m->assurances ? Badge::render('assurance', 'current', count($m->assurances) . ' on file') : '<span class="gw-null">&mdash;</span>';
            },
        ],
        [
            'label' => 'Posture',
            'format' => 'raw',
            'value' => static function (Supplier $m) {
                $p = $m->getPosture();
                return Badge::posture($p['state'], implode('; ', array_merge($p['failures'], $p['unknown'])) ?: null);
            },
        ],
        ['class' => ActionColumn::class],
    ],
]) ?>
