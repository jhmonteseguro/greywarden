<?php
/**
 * @var yii\web\View $this
 * @var app\modules\risk\models\Risk $model
 */

$this->title = 'New risk';
$this->params['breadcrumbs'][] = ['label' => 'Risk Register', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
