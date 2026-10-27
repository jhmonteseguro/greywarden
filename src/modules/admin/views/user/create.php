<?php
/**
 * @var yii\web\View $this
 * @var app\modules\admin\models\UserForm $model
 */

$this->title = 'New user';
$this->params['breadcrumbs'][] = 'Admin';
$this->params['breadcrumbs'][] = ['label' => 'Users', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
