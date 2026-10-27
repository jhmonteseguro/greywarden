<?php
/**
 * @var yii\web\View $this
 * @var app\modules\intel\models\BlocklistEntry $model
 */

$this->title = 'Block an indicator';
$this->params['breadcrumbs'][] = ['label' => 'Blocklist', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('_form', ['model' => $model]) ?>
