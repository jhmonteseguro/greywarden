<?php
/**
 * @var yii\web\View $this
 * @var app\modules\admin\models\UserSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\User;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;

$this->title = 'Users';
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = $this->title;
$this->params['actions'] = Html::a('<i class="bi bi-person-plus"></i> New user', ['create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']);
?>
<?= QuickFilters::widget([
    'filters' => ['' => 'All'] + array_map(static fn($l) => $l . 's', Rbac::roleLabels()),
    'param' => 'role',
    'current' => (string) $searchModel->role,
    'query' => $searchModel->q,
    'placeholder' => 'Search name, username, email…',
]) ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'columns' => [
        [
            'attribute' => 'full_name',
            'format' => 'raw',
            'value' => static fn(User $u) => Html::a(Html::encode($u->full_name), ['view', 'id' => $u->id], ['class' => 'gw-title-link'])
                . '<div class="gw-muted gw-small">' . Html::encode($u->job_title) . '</div>',
            'filter' => false,
        ],
        ['attribute' => 'username', 'filter' => false],
        ['attribute' => 'email', 'format' => 'email', 'filter' => false],
        [
            'label' => 'Role',
            'format' => 'raw',
            'value' => static fn(User $u) => Badge::render('role', $u->getRoleName(), $u->getRoleLabel()),
        ],
        [
            'attribute' => 'status',
            'format' => 'raw',
            'value' => static fn(User $u) => Badge::render('user-status', (string) $u->status, User::statusLabels()[$u->status] ?? '?')
                . ($u->must_change_password ? ' ' . Badge::flag('pending', 'Must change password') : ''),
            'filter' => User::statusLabels(),
        ],
        ['attribute' => 'last_login_at', 'format' => 'relativeTime', 'filter' => false],
        [
            'class' => \app\widgets\grid\ActionColumn::class,
            'permission' => Rbac::PERM_USERS,
        ],
    ],
]) ?>
