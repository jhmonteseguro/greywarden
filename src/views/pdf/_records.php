<?php
/**
 * Table of linked records: reference, title and an optional extra column.
 * @var string $title
 * @var app\models\DomainRecord[] $models
 * @var string|null $extraLabel heading of the extra column
 * @var callable|null $extra callable(DomainRecord): string extra cell HTML
 * @var string $emptyText optional
 */

use app\helpers\Badge;
use yii\helpers\Html;

$extra ??= null;
?>
<h3><?= Html::encode($title) ?></h3>
<?php if (!$models): ?>
    <p class="null"><?= Html::encode($emptyText ?? 'None linked.') ?></p>
<?php else: ?>
    <table class="list">
        <thead><tr><th>Ref</th><th>Title</th><?= $extra ? '<th>' . Html::encode($extraLabel ?? '') . '</th>' : '' ?></tr></thead>
        <tbody>
        <?php foreach ($models as $m): ?>
            <tr>
                <td class="ref"><span class="ref"><?= Html::encode($m->getReferenceValue()) ?></span></td>
                <td><?= Html::encode($m->getTitleValue()) ?><?= $m->getIsArchived() ? ' ' . Badge::flag('archived', 'Archived') : '' ?></td>
                <?= $extra ? '<td>' . $extra($m) . '</td>' : '' ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
