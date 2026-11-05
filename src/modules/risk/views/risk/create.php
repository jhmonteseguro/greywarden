<?php
/**
 * @var yii\web\View $this
 * @var app\modules\risk\models\Risk $model
 */

use app\modules\threat\components\StoryRaiser;

$this->title = 'New risk';
$this->params['breadcrumbs'][] = ['label' => 'Risk Register', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'New';
?>
<?= $this->render('@app/modules/threat/views/story/_raising', ['triage' => StoryRaiser::requested(), 'finish' => 'Choose a category and score the likelihood and impact.']) ?>
<?= $this->render('_form', ['model' => $model]) ?>
