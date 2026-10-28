<?php
/**
 * @var yii\web\View $this
 * @var app\modules\cloud\models\CloudAccount $model
 */

$this->title = 'Add cloud account';
$this->params['breadcrumbs'][] = ['label' => 'Cloud Accounts', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
