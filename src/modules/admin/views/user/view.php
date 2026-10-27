<?php
/**
 * @var yii\web\View $this
 * @var app\models\User $user
 * @var array $attempts
 * @var int $locked seconds remaining on a lockout
 */

use app\helpers\Badge;
use app\models\AuditLog;
use app\models\User;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use yii\helpers\Html;

$this->title = $user->full_name;
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = ['label' => 'Users', 'url' => ['index']];
$this->params['breadcrumbs'][] = $user->username;
$this->params['subtitle'] = Badge::render('role', $user->getRoleName(), $user->getRoleLabel()) . ' '
    . Badge::render('user-status', (string) $user->status, User::statusLabels()[$user->status] ?? '?')
    . ($locked > 0 ? ' ' . Badge::flag('overdue', 'Locked out') : '');
$isSelf = (int) $user->id === (int) Yii::$app->user->id;
$this->params['actions'] = Html::a('<i class="bi bi-pencil-square"></i> Edit', ['update', 'id' => $user->id], ['class' => 'btn btn-primary gw-btn'])
    . ' ' . Html::a('<i class="bi bi-key"></i> Reset password', ['reset-password', 'id' => $user->id], ['class' => 'btn btn-outline-warning gw-btn'])
    . ($locked > 0 ? ' ' . Html::a('<i class="bi bi-unlock"></i> Clear lockout', ['unlock', 'id' => $user->id], ['class' => 'btn btn-outline-info gw-btn', 'data-method' => 'post']) : '')
    . ($isSelf ? '' : ' ' . Html::a(
        $user->isActive() ? '<i class="bi bi-person-x"></i> Disable' : '<i class="bi bi-person-check"></i> Enable',
        ['toggle-status', 'id' => $user->id],
        ['class' => 'btn ' . ($user->isActive() ? 'btn-outline-danger' : 'btn-outline-success') . ' gw-btn', 'data-method' => 'post',
         'data-confirm' => $user->isActive() ? 'Disable this user? They will be signed out immediately.' : null]
    ));
$fmt = Yii::$app->formatter;
$entries = AuditLog::find()->where(['entity_type' => 'user', 'entity_id' => $user->id])->orderBy(['id' => SORT_DESC])->limit(100)->all();
?>
<div class="row g-4">
    <div class="col-xl-6">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Account</h2>
            <?= DetailView::widget([
                'model' => $user,
                'attributes' => [
                    'username', 'full_name', 'job_title', 'email:email',
                    ['label' => 'Role', 'value' => $user->getRoleLabel()],
                    'must_change_password:boolean',
                    'password_changed_at:datetime',
                    'last_login_at:datetime',
                    'last_login_ip',
                    'created_at:datetime',
                ],
            ]) ?>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Recent sign-in attempts</h2>
            <?php if (!$attempts): ?>
                <p class="gw-empty">No sign-in attempts recorded.</p>
            <?php else: ?>
                <table class="table table-sm gw-table">
                    <thead><tr><th>When</th><th>Result</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($attempts as $a): ?>
                        <tr>
                            <td><?= $fmt->asDatetime($a['attempted_at']) ?></td>
                            <td><?= $a['success'] ? Badge::auditAction('login', 'Success') : Badge::auditAction('login_failed', 'Failed') ?></td>
                            <td><code class="gw-code"><?= Html::encode($a['ip']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-xl-6">
        <section class="gw-panel">
            <h2 class="gw-panel__title">History</h2>
            <?= AuditHistory::widget(['entityType' => 'user', 'entityId' => $user->id, 'entries' => $entries]) ?>
        </section>
    </div>
</div>
