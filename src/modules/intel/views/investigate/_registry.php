<?php
/**
 * WHOIS & RDAP tab: the structured RDAP record next to the raw WHOIS text.
 * @var yii\web\View $this
 * @var app\modules\intel\components\Report $report
 */

use app\modules\intel\components\IntelHtml;
use app\modules\intel\components\SourceRegistry;
use yii\helpers\Html;

$rdap = $report->result('rdap');
$whois = $report->result('whois');
?>
<div class="row g-3">
    <div class="col-xl-5">
        <?php if ($rdap): ?>
            <?= $this->render('_source-panel', ['key' => 'rdap', 'source' => SourceRegistry::get('rdap'), 'result' => $rdap, 'indicator' => $report->indicator]) ?>
        <?php endif; ?>
    </div>
    <div class="col-xl-7">
        <section class="gw-panel gw-intel-source gw-intel-status--<?= $whois->status ?? 'skipped' ?>" id="src-whois">
            <header class="gw-panel__header">
                <h3 class="gw-panel__title"><span class="gw-intel-dot" aria-hidden="true"></span><i class="bi bi-terminal" aria-hidden="true"></i> WHOIS
                    <?php if ($whois && isset($whois->rows['Server'])): ?><span class="gw-intel-whois-server gw-mono"><?= Html::encode($whois->rows['Server']) ?></span><?php endif; ?></h3>
                <div class="gw-panel__tools"><?= $whois ? IntelHtml::status($whois) : '' ?></div>
            </header>
            <?php if (!$whois || !$whois->raw): ?>
                <p class="gw-empty"><?= Html::encode($whois->summary ?? 'Not queried.') ?></p>
            <?php else: ?>
                <?php $fields = array_diff_key($whois->rows, ['Server' => 1]); ?>
                <?php if ($fields): ?>
                    <dl class="gw-intel-rows gw-intel-rows--inline">
                        <?php foreach ($fields as $label => $value): ?>
                            <dt><?= Html::encode($label) ?></dt><dd><?= IntelHtml::value($value) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
                <div class="gw-terminal gw-terminal--tall">
                    <div class="gw-terminal__bar" aria-hidden="true">
                        <span></span><span></span><span></span>
                        <code>whois <?= Html::encode($report->indicator->value) ?></code>
                    </div>
                    <button type="button" class="gw-terminal__copy" data-gw-copy-target="#raw-whois" title="Copy"><i class="bi bi-copy"></i><span class="visually-hidden">Copy</span></button>
                    <pre class="gw-terminal__body" id="raw-whois"><?= preg_replace(
                        ['/^([%#].*)$/m', '/^([A-Za-z][\w-]*:)/m'],
                        ['<span class="gw-terminal__comment">$1</span>', '<span class="gw-terminal__key">$1</span>'],
                        Html::encode($whois->raw)
                    ) ?></pre>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
