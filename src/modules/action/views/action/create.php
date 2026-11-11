<?php
/**
 * @var yii\web\View $this
 * @var app\modules\action\models\Action $model
 */

$this->title = 'New action';
$this->params['breadcrumbs'][] = ['label' => 'Actions', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
