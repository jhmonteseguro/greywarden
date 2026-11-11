<?php
/**
 * @var yii\web\View $this
 * @var app\modules\action\models\Action $model
 */

$this->title = 'Edit ' . $model->reference;
$this->params['breadcrumbs'][] = ['label' => 'Actions', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->reference, 'url' => $model->getViewUrl()];
$this->params['breadcrumbs'][] = 'Edit';
?>
<?= $this->render('_form', ['model' => $model]) ?>
