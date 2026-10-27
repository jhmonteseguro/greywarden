<?php
/**
 * Two label/value pairs per row. Values are HTML (encode text before passing);
 * null or '' prints "—".
 * @var array<string, string|null> $facts label => value HTML
 */

use yii\helpers\Html;

$cells = [];
foreach ($facts as $label => $value) {
    $cells[] = '<th>' . Html::encode($label) . '</th><td>' . ($value === null || $value === '' ? '<span class="null">—</span>' : $value) . '</td>';
}
?>
<table class="facts">
    <?php foreach (array_chunk($cells, 2) as $row): ?>
        <tr><?= implode('', $row) ?><?= count($row) === 1 ? '<th></th><td></td>' : '' ?></tr>
    <?php endforeach; ?>
</table>
