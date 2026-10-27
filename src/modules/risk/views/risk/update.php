<?php
/**
 * @var yii\web\View $this
 * @var app\modules\risk\models\Risk $model
 */

$this->title = 'Edit ' . $model->reference;
$this->params['breadcrumbs'][] = ['label' => 'Risk Register', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->reference, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Edit';
?>
<?= $this->render('_form', ['model' => $model]) ?>
