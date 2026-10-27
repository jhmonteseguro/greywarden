<?php
/**
 * @var yii\web\View $this
 * @var app\modules\admin\models\UserForm $model
 */

$this->title = 'Edit ' . $model->getUser()->username;
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = ['label' => 'Users', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->getUser()->username, 'url' => ['view', 'id' => $model->getUser()->id]];
$this->params['breadcrumbs'][] = 'Edit';
?>
<?= $this->render('_form', ['model' => $model]) ?>
