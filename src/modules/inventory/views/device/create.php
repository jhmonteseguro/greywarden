<?php
/**
 * @var yii\web\View $this
 * @var app\modules\inventory\models\Device $model
 */

$this->title = 'Add device';
$this->params['breadcrumbs'][] = ['label' => 'Device Inventory', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
