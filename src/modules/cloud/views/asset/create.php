<?php
/**
 * @var yii\web\View $this
 * @var app\modules\cloud\models\CloudAsset $model
 */

$this->title = 'Add cloud asset';
$this->params['breadcrumbs'][] = ['label' => 'Cloud Inventory', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
