<?php
/**
 * One source's answer: summary, rows, tables, raw response.
 * @var yii\web\View $this
 * @var string $key
 * @var app\modules\intel\components\sources\Source $source
 * @var app\modules\intel\components\SourceResult $result
 * @var app\modules\intel\components\Indicator $indicator
 * @var int|null $position place in the original order (single-column layout)
 */

use app\modules\intel\components\IntelHtml;
use yii\helpers\Html;

$reportUrl = $source->reportUrl($indicator);
$homepage = $source->homepage();
?>
<section class="gw-panel gw-intel-source gw-intel-status--<?= $result->status ?>" id="src-<?= Html::encode($key) ?>"<?= isset($position) ? ' style="order: ' . (int) $position . '"' : '' ?>>
    <header class="gw-panel__header gw-intel-source__header">
        <h3 class="gw-panel__title">
            <span class="gw-intel-dot" aria-hidden="true"></span>
            <i class="bi bi-<?= IntelHtml::CATEGORY_ICONS[$source->category()] ?? 'circle' ?>" aria-hidden="true"></i>
            <?= Html::encode($source->label()) ?>
        </h3>
        <div class="gw-panel__tools">
            <?= IntelHtml::status($result) ?>
            <?php if ($result->cached): ?>
                <span class="gw-intel-source__time" title="Cached answer"><i class="bi bi-database" aria-hidden="true"></i> <?= Html::encode(IntelHtml::age($result->fetchedAt)) ?></span>
            <?php elseif ($result->answered() || $result->status === 'error'): ?>
                <span class="gw-intel-source__time"><?= IntelHtml::duration($result->time) ?></span>
            <?php endif; ?>
            <?php if ($reportUrl): ?>
                <?= Html::a('<i class="bi bi-box-arrow-up-right"></i>', $reportUrl, ['class' => 'gw-icon-btn', 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Open on ' . parse_url($reportUrl, PHP_URL_HOST), 'aria-label' => 'Open on provider site']) ?>
            <?php endif; ?>
        </div>
    </header>

    <p class="gw-intel-source__summary"><?= Html::encode($result->summary ?: $source->description()) ?></p>

    <?php if ($result->rows): ?>
        <dl class="gw-intel-rows">
            <?php foreach ($result->rows as $label => $value): ?>
                <dt><?= Html::encode($label) ?></dt>
                <dd><?= IntelHtml::value($value) ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <?php foreach ($result->lists as $name => $table):
        $keyCol = array_search('key', $table['columns'], true);
        $resultCol = array_search('Result', $table['columns'], true); ?>
        <div class="gw-intel-table-wrap">
            <h4 class="gw-textblock__label"><?= Html::encode($name) ?> <span class="gw-muted">(<?= count($table['rows']) ?>)</span></h4>
            <div class="table-responsive">
                <table class="table gw-coverage__table gw-intel-table">
                    <thead><tr><?php foreach ($table['columns'] as $ci => $col): if ($ci === $keyCol) { continue; } ?><th scope="col"><?= Html::encode($col) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($table['rows'] as $row):
                        $state = $resultCol !== false && in_array($row[$resultCol] ?? null, ['listed', 'match', 'clean', 'error', 'policy', 'unavailable', 'not loaded'], true) ? str_replace(' ', '-', $row[$resultCol]) : null; ?>
                        <tr class="<?= $state ? 'gw-intel-row--' . $state : '' ?>">
                            <?php foreach ($row as $ci => $cell): if ($ci === $keyCol) { continue; } ?>
                                <td><?php if ($ci === $resultCol && $state): ?><span class="gw-intel-result gw-intel-result--<?= $state ?>"><?= Html::encode($cell) ?></span><?php else: ?><?= IntelHtml::value($cell) ?><?php endif; ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($result->raw): ?>
        <details class="gw-intel-raw">
            <summary><i class="bi bi-code-slash" aria-hidden="true"></i> Raw response</summary>
            <div class="gw-terminal">
                <button type="button" class="gw-terminal__copy" data-gw-copy-target="#raw-<?= Html::encode($key) ?>" title="Copy"><i class="bi bi-copy"></i><span class="visually-hidden">Copy</span></button>
                <pre class="gw-terminal__body" id="raw-<?= Html::encode($key) ?>"><?= Html::encode($result->raw) ?></pre>
            </div>
        </details>
    <?php endif; ?>

    <footer class="gw-intel-source__foot">
        <span><?= Html::encode($source->description()) ?></span>
        <?= Html::a(Html::encode(parse_url($homepage, PHP_URL_HOST) ?: 'Sources'), $homepage, str_starts_with($homepage, 'http') ? ['target' => '_blank', 'rel' => 'noopener noreferrer'] : []) ?>
    </footer>
</section>
