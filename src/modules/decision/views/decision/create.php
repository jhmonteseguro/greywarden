<?php
/**
 * @var yii\web\View $this
 * @var app\modules\decision\models\Decision $model
 */

$this->title = 'New decision';
$this->params['breadcrumbs'][] = ['label' => 'Decision Log', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
