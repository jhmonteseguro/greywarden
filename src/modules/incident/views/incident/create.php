<?php
/**
 * @var yii\web\View $this
 * @var app\modules\incident\models\Incident $model
 */

use app\modules\threat\components\StoryRaiser;

$this->title = 'Log incident';
$this->params['breadcrumbs'][] = ['label' => 'Incident Log', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('@app/modules/threat/views/story/_raising', ['triage' => StoryRaiser::requested(), 'finish' => 'Check the severity and choose a category.']) ?>
<?= $this->render('_form', ['model' => $model]) ?>
