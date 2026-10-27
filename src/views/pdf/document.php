<?php
/**
 * Print document for components/RecordPdf: letterhead, record heading and a
 * light print stylesheet (dompdf supports CSS 2.1 tables/blocks, not flex/grid).
 *
 * @var yii\web\View $this
 * @var app\models\DomainRecord $model
 * @var string $content body rendered by the module's `pdf` view
 * @var string $generatedAt
 * @var string $generatedBy
 */

use app\components\Branding;
use yii\helpers\Html;

$organisation = Branding::organisation();
$classification = Branding::classification();
$logo = Branding::logoDataUri();
$register = $this->params['pdf.register'] ?? $model::entityLabel();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= Html::encode($model->getReferenceValue() . ' ' . $model->getTitleValue()) ?></title>
<style>
    @page { margin: 18mm 16mm 20mm 16mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; line-height: 1.45; color: #1f2933; }
    .letterhead { width: 100%; border-collapse: collapse; border-bottom: 2px solid #1f2933; margin-bottom: 14pt; }
    .letterhead td { padding: 0 0 6pt; vertical-align: bottom; }
    .letterhead td.letterhead__logo { width: 1%; padding-right: 8pt; }
    .letterhead__logo img { height: 30pt; }
    .letterhead__org { font-size: 10pt; font-weight: bold; }
    .letterhead__register { font-size: 7.5pt; letter-spacing: 1pt; text-transform: uppercase; color: #52606d; }
    .letterhead__marking { text-align: right; }
    .marking { display: inline-block; border: 1px solid #b42318; color: #b42318; font-size: 7.5pt; font-weight: bold;
        letter-spacing: 1pt; text-transform: uppercase; padding: 2pt 6pt; }

    h1 { font-size: 15pt; line-height: 1.25; margin: 0 0 4pt; }
    .subtitle { margin: 0 0 12pt; color: #52606d; }
    .ref { font-family: "DejaVu Sans Mono", monospace; font-weight: bold; color: #1f2933; }
    h2 { font-size: 10.5pt; margin: 16pt 0 6pt; padding-bottom: 3pt; border-bottom: 1px solid #cbd2d9; page-break-after: avoid; }
    h3 { font-size: 8pt; margin: 9pt 0 2pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #52606d; page-break-after: avoid; }
    p { margin: 0 0 6pt; }
    .muted { color: #7b8794; }
    .null { color: #9aa5b1; font-style: italic; }
    .small { font-size: 7.5pt; }
    .nowrap { white-space: nowrap; }

    table.facts { width: 100%; border-collapse: collapse; margin-bottom: 4pt; }
    table.facts th, table.facts td { padding: 3pt 6pt 3pt 0; vertical-align: top; text-align: left; border-bottom: 1px solid #e4e7eb; }
    table.facts th { width: 20%; font-weight: normal; color: #52606d; }
    table.facts td { width: 30%; }

    table.list { width: 100%; border-collapse: collapse; margin-bottom: 4pt; }
    table.list th { white-space: nowrap; text-align: left; font-size: 7.5pt; font-weight: bold; color: #52606d; text-transform: uppercase; letter-spacing: 0.4pt;
        padding: 3pt 6pt 3pt 0; border-bottom: 1px solid #9aa5b1; }
    table.list td { padding: 4pt 6pt 4pt 0; vertical-align: top; border-bottom: 1px solid #e4e7eb; }
    table.list tr { page-break-inside: avoid; }
    table.list td.ref { width: 62pt; white-space: nowrap; }

    .textblock { page-break-inside: auto; }
    .highlight { border-left: 3px solid #1f2933; background: #f5f7fa; padding: 6pt 8pt; margin: 6pt 0; }
    .highlight h3 { margin-top: 0; }

    table.scores { width: 100%; border-collapse: separate; border-spacing: 6pt 0; margin: 0 -6pt 4pt; }
    table.scores td { width: 33%; border: 1px solid #cbd2d9; padding: 6pt 8pt; vertical-align: top; }
    .scores__label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #52606d; }
    .scores__value { font-size: 18pt; font-weight: bold; line-height: 1.1; }
    .scores__rating { font-size: 9pt; }
    .scores__axes { font-size: 7.5pt; color: #52606d; }

    /* app\helpers\Badge markup, restyled for print */
    .gw-badge { display: inline-block; padding: 0.5pt 5pt; border: 1px solid #9aa5b1; border-radius: 6pt; font-size: 7.5pt; white-space: nowrap; color: #3e4c59; }
    .gw-badge .bi { display: none; }
    .gw-badge--success { border-color: #3f9142; color: #2b6e2e; }
    .gw-badge--info { border-color: #4a7ab8; color: #2f5d94; }
    .gw-badge--warning, .gw-badge--medium { border-color: #c98a00; color: #8a5d00; }
    .gw-badge--danger, .gw-badge--critical { border-color: #b42318; color: #b42318; }
    .gw-badge--high { border-color: #d0630c; color: #a44c06; }
    .gw-badge--low { border-color: #3f9142; color: #2b6e2e; }
    .gw-badge--muted { border-color: #cbd2d9; color: #7b8794; }
    .rating--critical { color: #b42318; } .rating--high { color: #a44c06; } .rating--medium { color: #8a5d00; } .rating--low { color: #2b6e2e; }
</style>
</head>
<body>
<table class="letterhead">
    <tr>
        <?php if ($logo): ?><td class="letterhead__logo"><img src="<?= $logo ?>" alt=""></td><?php endif; ?>
        <td>
            <?php if ($organisation !== ''): ?><div class="letterhead__org"><?= Html::encode($organisation) ?></div><?php endif; ?>
            <div class="letterhead__register"><?= Html::encode($register) ?></div>
        </td>
        <td class="letterhead__marking"><?php if ($classification !== ''): ?><span class="marking"><?= Html::encode($classification) ?></span><?php endif; ?></td>
    </tr>
</table>

<h1><?= Html::encode($model->getTitleValue()) ?></h1>
<p class="subtitle">
    <span class="ref"><?= Html::encode($model->getReferenceValue()) ?></span>
    <?= $this->params['pdf.badges'] ?? '' ?>
    <?php if ($model->getIsArchived()): ?> <span class="gw-badge gw-badge--muted">Archived</span><?php endif; ?>
    <span class="muted small">&nbsp; As at <?= Html::encode($generatedAt) ?></span>
</p>

<?= $content ?>

<h2>Record</h2>
<table class="facts">
    <tr>
        <th>Created</th><td><?= Yii::$app->formatter->asDatetime($model->created_at) ?> by <?= Html::encode($model->creator->full_name ?? 'system') ?></td>
        <th>Last updated</th><td><?= Yii::$app->formatter->asDatetime($model->updated_at) ?> by <?= Html::encode($model->updater->full_name ?? 'system') ?></td>
    </tr>
</table>
<p class="muted small">Exported by <?= Html::encode($generatedBy) ?>. The register is the authoritative copy; this document reflects the record at the time shown above.</p>
</body>
</html>
