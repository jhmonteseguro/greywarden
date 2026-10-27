<?php
/**
 * @var yii\web\View $this
 * @var string $type
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var array $counts
 */

use app\helpers\Badge;
use app\models\Lookup;
use app\widgets\GridView;
use yii\helpers\Html;

$this->title = 'Lookups';
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = $this->title;
$this->params['subtitle'] = 'Drop-down values used across the registers. Deactivate values that are in use instead of deleting them.';
$this->params['actions'] = Html::a('<i class="bi bi-plus-lg"></i> Add value', ['create', 'type' => $type], ['class' => 'btn btn-primary gw-btn gw-btn--create']);
?>
<nav class="gw-quickfilters gw-quickfilters--tabs" aria-label="Lookup lists">
    <?php foreach (Lookup::types() as $key => $label): ?>
        <?= Html::a(Html::encode($label) . ' <span class="gw-quickfilter__count">' . (int) ($counts[$key] ?? 0) . '</span>', ['index', 'type' => $key], [
            'class' => 'gw-quickfilter' . ($key === $type ? ' is-active' : ''),
        ]) ?>
    <?php endforeach; ?>
</nav>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'layout' => "<div class=\"table-responsive gw-grid__body\">{items}</div>",
    'columns' => [
        'name',
        'description',
        'sort_order',
        ['attribute' => 'is_active', 'format' => 'raw', 'value' => static fn(Lookup $l) => Badge::bool($l->is_active, 'Active', 'Inactive')],
        ['label' => 'In use', 'value' => static fn(Lookup $l) => $l->getUsageCount()],
        [
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-actions'],
            'value' => static fn(Lookup $l) => Html::a('<i class="bi bi-pencil-square"></i>', ['update', 'id' => $l->id], ['class' => 'gw-icon-btn', 'title' => 'Edit', 'aria-label' => 'Edit'])
                . ' ' . Html::a('<i class="bi bi-' . ($l->is_active ? 'eye-slash' : 'eye') . '"></i>', ['toggle', 'id' => $l->id], ['class' => 'gw-icon-btn', 'title' => $l->is_active ? 'Deactivate' : 'Activate', 'aria-label' => $l->is_active ? 'Deactivate' : 'Activate', 'data-method' => 'post'])
                . ' ' . Html::a('<i class="bi bi-trash"></i>', ['delete', 'id' => $l->id], ['class' => 'gw-icon-btn gw-icon-btn--danger', 'title' => 'Delete', 'aria-label' => 'Delete', 'data-method' => 'post', 'data-confirm' => 'Delete this value?']),
        ],
    ],
]) ?>
