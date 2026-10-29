<?php
/**
 * @var yii\web\View $this
 * @var app\modules\supplier\models\Supplier $model
 */

$this->title = 'Add supplier';
$this->params['breadcrumbs'][] = ['label' => 'Supplier Register', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
