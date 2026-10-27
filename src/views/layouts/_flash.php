<?php
/**
 * Flash messages (success, info, warning, danger/error).
 * @var yii\web\View $this
 */

use yii\helpers\Html;

$map = ['error' => 'danger', 'danger' => 'danger', 'success' => 'success', 'info' => 'info', 'warning' => 'warning'];
$icons = ['danger' => 'x-octagon', 'success' => 'check-circle', 'info' => 'info-circle', 'warning' => 'exclamation-triangle'];
$flashes = Yii::$app->session->getAllFlashes(true);
if (!$flashes) {
    return;
}
?>
<div class="gw-flash" role="status" aria-live="polite">
    <?php foreach ($flashes as $type => $messages): ?>
        <?php $tone = $map[$type] ?? 'info'; ?>
        <?php foreach ((array) $messages as $message): ?>
            <div class="alert alert-<?= $tone ?> alert-dismissible fade show gw-flash__item gw-flash__item--<?= $tone ?>" role="alert">
                <i class="bi bi-<?= $icons[$tone] ?>" aria-hidden="true"></i>
                <span class="gw-flash__text"><?= Html::encode($message) ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
