<?php
/**
 * Labelled multi-line text section for view pages.
 * @var string $label
 * @var string|null $text
 */

use yii\helpers\Html;

?>
<div class="gw-textblock">
    <h3 class="gw-textblock__label"><?= Html::encode($label) ?></h3>
    <?php if ($text === null || trim($text) === ''): ?>
        <p class="gw-textblock__body gw-null">Not recorded.</p>
    <?php else: ?>
        <div class="gw-textblock__body"><?= nl2br(Html::encode($text)) ?></div>
    <?php endif; ?>
</div>
