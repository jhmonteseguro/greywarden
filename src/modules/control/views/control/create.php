<?php
/**
 * @var yii\web\View $this
 * @var app\modules\control\models\Control $model
 */

$this->title = 'New control';
$this->params['breadcrumbs'][] = ['label' => 'Control Register', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
