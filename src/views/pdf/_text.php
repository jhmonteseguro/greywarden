<?php
/**
 * Labelled multi-line text (PDF counterpart of views/partials/_text-block).
 * @var string $label
 * @var string|null $text
 * @var bool $highlight optional
 * @var string $emptyText optional, shown when there is no text (default "Not recorded.")
 */

use yii\helpers\Html;

$empty = $text === null || trim($text) === '';
?>
<div class="textblock<?= !empty($highlight) ? ' highlight' : '' ?>">
    <h3><?= Html::encode($label) ?></h3>
    <?= $empty ? '<p class="null">' . Html::encode($emptyText ?? 'Not recorded.') . '</p>' : '<p>' . nl2br(Html::encode($text)) . '</p>' ?>
</div>
