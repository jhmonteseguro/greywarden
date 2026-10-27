<?php
/**
 * @var yii\web\View $this
 * @var app\modules\inventory\models\Device $model
 */

$this->title = 'Edit ' . $model->asset_tag;
$this->params['breadcrumbs'][] = ['label' => 'Device Inventory', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->getOldAttribute('asset_tag'), 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Edit';
?>
<?= $this->render('_form', ['model' => $model]) ?>
