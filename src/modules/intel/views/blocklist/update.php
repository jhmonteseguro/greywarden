<?php
/**
 * @var yii\web\View $this
 * @var app\modules\intel\models\BlocklistEntry $model
 */

$this->title = 'Edit ' . $model->reference;
$this->params['breadcrumbs'][] = ['label' => 'Blocklist', 'url' => ['index', 'kind' => $model->indicator_type === 'asn' ? 'asn' : 'ip']];
$this->params['breadcrumbs'][] = ['label' => $model->reference, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Edit';
?>
<?= $this->render('_form', ['model' => $model]) ?>
