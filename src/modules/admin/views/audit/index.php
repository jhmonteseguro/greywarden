<?php
/**
 * @var yii\web\View $this
 * @var app\modules\admin\models\AuditLogSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use app\helpers\Badge;
use app\models\AuditLog;
use app\models\User;
use app\widgets\GridView;
use yii\helpers\Html;

$this->title = 'Audit log';
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = $this->title;
$this->params['subtitle'] = 'Every create, update, archive, restore, delete and sign-in event.';
?>
<form class="gw-filterbar" method="get" action="">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6 col-xl">
            <label class="form-label gw-field__label" for="af-q">Record / reference</label>
            <input id="af-q" type="text" name="q" class="form-control form-control-sm" value="<?= Html::encode($searchModel->q) ?>">
        </div>
        <div class="col-6 col-md-4 col-xl">
            <label class="form-label gw-field__label" for="af-type">Type</label>
            <?= Html::dropDownList('entity_type', $searchModel->entity_type, AuditLog::entityLabels(), ['prompt' => 'Any', 'class' => 'form-select form-select-sm', 'id' => 'af-type']) ?>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <label class="form-label gw-field__label" for="af-action">Action</label>
            <?= Html::dropDownList('action', $searchModel->action, AuditLog::ACTION_LABELS, ['prompt' => 'Any', 'class' => 'form-select form-select-sm', 'id' => 'af-action']) ?>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <label class="form-label gw-field__label" for="af-user">User</label>
            <?= Html::dropDownList('user_id', $searchModel->user_id, User::find()->select(['username', 'id'])->indexBy('id')->orderBy('username')->column(), ['prompt' => 'Any', 'class' => 'form-select form-select-sm', 'id' => 'af-user']) ?>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <label class="form-label gw-field__label" for="af-from">From</label>
            <input id="af-from" type="date" name="date_from" class="form-control form-control-sm" value="<?= Html::encode($searchModel->date_from) ?>">
        </div>
        <div class="col-6 col-md-4 col-xl">
            <label class="form-label gw-field__label" for="af-to">To</label>
            <input id="af-to" type="date" name="date_to" class="form-control form-control-sm" value="<?= Html::encode($searchModel->date_to) ?>">
        </div>
        <div class="col-6 col-md-4 col-xl-auto d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <?= Html::a('Reset', ['index'], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
        </div>
    </div>
</form>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'columns' => [
        ['attribute' => 'created_at', 'format' => 'datetime', 'label' => 'When'],
        ['attribute' => 'username', 'label' => 'User'],
        ['attribute' => 'action', 'format' => 'raw', 'value' => static fn(AuditLog $e) => Badge::auditAction($e->action, $e->getActionLabel())],
        ['attribute' => 'entity_type', 'label' => 'Type', 'value' => static fn(AuditLog $e) => $e->getEntityLabelText()],
        [
            'label' => 'Record',
            'format' => 'raw',
            'value' => static fn(AuditLog $e) => ($url = $e->getEntityUrl()) ? Html::a(Html::encode($e->entity_label), $url, ['class' => 'gw-ref']) : Html::encode($e->entity_label),
        ],
        [
            'label' => 'Changes',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-changes'],
            'value' => static function (AuditLog $e) {
                $rows = $e->getFormattedChanges();
                if (!$rows) {
                    return '';
                }
                $fields = array_map(static fn($r) => $r['field'], array_slice($rows, 0, 5));
                return Html::encode(implode(', ', $fields) . (count($rows) > 5 ? ' +' . (count($rows) - 5) . ' more' : ''));
            },
        ],
        ['attribute' => 'ip', 'label' => 'IP', 'format' => 'raw', 'value' => static fn(AuditLog $e) => $e->ip ? '<code class="gw-code">' . Html::encode($e->ip) . '</code>' : ''],
        [
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-actions'],
            'value' => static fn(AuditLog $e) => Html::a('<i class="bi bi-eye"></i>', ['view', 'id' => $e->id], ['class' => 'gw-icon-btn', 'title' => 'Details', 'aria-label' => 'Details']),
        ],
    ],
]) ?>
