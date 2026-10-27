<?php
/**
 * @var yii\web\View $this
 * @var app\modules\incident\models\Incident $model
 */

$this->title = 'Log incident';
$this->params['breadcrumbs'][] = ['label' => 'Incident Log', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
