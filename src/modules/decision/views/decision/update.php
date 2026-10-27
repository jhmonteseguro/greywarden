<?php
/**
 * @var yii\web\View $this
 * @var app\modules\decision\models\Decision $model
 */

$this->title = 'Edit ' . $model->reference;
$this->params['breadcrumbs'][] = ['label' => 'Decision Log', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->reference, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Edit';
?>
<?= $this->render('_form', ['model' => $model]) ?>
