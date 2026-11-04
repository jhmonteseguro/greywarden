<?php
/**
 * One story: why it ranks where it does, its CVEs, and who covered it. Links go to the
 * publisher; Greywarden only keeps the headline and a short extract.
 *
 * @var yii\web\View $this
 * @var app\modules\threat\models\ThreatTriage $model
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\threat\components\StoryScorer;
use app\modules\threat\models\ThreatTriage;
use app\widgets\AuditHistory;
use yii\helpers\Html;

$story = $model->story;
$this->title = $story->title;
$this->params['breadcrumbs'][] = ['label' => 'Threat Watch', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->getReferenceValue();
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->getReferenceValue()) . '</span> '
    . Badge::render('threat-status', $model->status, $model->getStatusLabel()) . ' '
    . Badge::render('threat-priority', $model->priority, ucfirst($model->priority) . ' priority');

$canManage = Rbac::canManage();
$buttons = [];
if ($canManage) {
    if ($model->status !== ThreatTriage::STATUS_NEW) {
        $buttons[] = Html::a('<i class="bi bi-arrow-counterclockwise"></i> Reopen', ['reopen', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn', 'data-method' => 'post']);
    }
    if ($model->status !== ThreatTriage::STATUS_DISMISSED) {
        $buttons[] = Html::a('<i class="bi bi-x-circle"></i> Dismiss', ['dismiss', 'id' => $model->id], ['class' => 'btn btn-outline-secondary gw-btn', 'data-method' => 'post']);
    }
    if ($model->status !== ThreatTriage::STATUS_RELEVANT) {
        $buttons[] = Html::a('<i class="bi bi-bookmark-check"></i> Relevant', ['relevant', 'id' => $model->id], ['class' => 'btn btn-primary gw-btn', 'data-method' => 'post']);
    }
}
$this->params['actions'] = implode("\n", $buttons);

$fmt = Yii::$app->formatter;
$signals = $model->getSignalList();
$cves = $story->getSortedCves();
$tone = static fn(int $weight) => match (true) {
    $weight >= 40 => 'critical',
    $weight >= 25 => 'high',
    $weight >= 10 => 'medium',
    default => 'info',
};
?>
<?php if ($model->note): ?>
    <div class="alert alert-secondary gw-callout" role="status"><i class="bi bi-sticky" aria-hidden="true"></i> <?= Html::encode($model->note) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <?php if ($story->summary): ?>
            <section class="gw-panel">
                <p class="gw-threat-summary"><?= Html::encode($story->summary) ?></p>
            </section>
        <?php endif; ?>

        <section class="gw-panel gw-panel--flush">
            <div class="gw-panel__header gw-panel__header--flush">
                <h2 class="gw-panel__title"><i class="bi bi-newspaper" aria-hidden="true"></i> Coverage <span class="gw-panel__count"><?= count($story->articles) ?></span></h2>
            </div>
            <ul class="gw-threat-articles">
                <?php foreach ($story->articles as $article): ?>
                    <li class="gw-threat-article">
                        <a class="gw-threat-article__title" href="<?= Html::encode($article->url) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= Html::encode($article->title) ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="visually-hidden"> (opens <?= Html::encode($article->getHost()) ?> in a new tab)</span></a>
                        <div class="gw-muted gw-intel-when"><?= Html::encode($article->source->name) ?> · <?= Html::encode($article->getHost()) ?> · <?= $fmt->asRelativeTime($article->published_at) ?></div>
                        <?php if ($article->excerpt && $article->excerpt !== $story->summary): ?><p class="gw-threat-article__excerpt"><?= Html::encode($article->excerpt) ?></p><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <?php if ($cves): ?>
            <section class="gw-panel gw-panel--flush">
                <div class="gw-panel__header gw-panel__header--flush">
                    <h2 class="gw-panel__title"><i class="bi bi-bug" aria-hidden="true"></i> Vulnerabilities <span class="gw-panel__count"><?= count($cves) ?></span></h2>
                </div>
                <div class="table-responsive">
                    <table class="table gw-table">
                        <thead><tr><th scope="col">CVE</th><th scope="col">Exploited</th><th scope="col" class="text-end">CVSS</th><th scope="col" class="text-end" title="Chance of exploitation in the next 30 days">EPSS</th></tr></thead>
                        <tbody>
                        <?php foreach ($cves as $cve): ?>
                            <tr>
                                <td>
                                    <a class="gw-mono" href="<?= Html::encode($cve->getNvdUrl()) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= Html::encode($cve->cve_id) ?></a>
                                    <?php if ($cve->vendor || $cve->product): ?><div class="gw-muted gw-intel-when"><?= Html::encode(trim($cve->vendor . ' ' . $cve->product)) ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($cve->getInKev()): ?>
                                        <?= Badge::render('threat-flag', 'kev', 'KEV', ['title' => 'Listed by CISA on ' . $fmt->asDate($cve->kev_added_on)]) ?>
                                        <?php if ($cve->kev_ransomware): ?><?= Badge::render('threat-flag', 'ransomware', 'Ransomware') ?><?php endif; ?>
                                        <?php if ($cve->kev_due_on): ?><div class="gw-muted gw-intel-when">CISA deadline <?= $fmt->asDate($cve->kev_due_on) ?></div><?php endif; ?>
                                    <?php else: ?>
                                        <span class="gw-muted">Not in KEV</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end gw-mono"><?= $cve->cvss_score !== null ? Html::encode(number_format((float) $cve->cvss_score, 1)) : '<span class="gw-muted" title="NVD has no score yet">—</span>' ?></td>
                                <td class="text-end gw-mono"><?= $cve->getEpssPercent() !== null ? Html::encode($cve->getEpssPercent()) : '<span class="gw-muted">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-activity" aria-hidden="true"></i> Why it ranks here <span class="gw-panel__count"><?= count($signals) ?></span></h2>
            <?php if (!$signals): ?>
                <p class="gw-empty">Nothing raises this story: it is not on your watchlist and there is no sign of exploitation.</p>
            <?php else: ?>
                <ol class="gw-intel-signal-list">
                    <?php foreach ($signals as $signal): ?>
                        <li class="gw-intel-signal gw-threat-signal gw-tone--<?= $tone((int) $signal['weight']) ?>">
                            <span class="gw-intel-signal__weight">+<?= (int) $signal['weight'] ?></span>
                            <span class="gw-intel-signal__body"><span class="gw-intel-signal__label"><?= Html::encode($signal['label']) ?></span></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if (StoryScorer::isBroad($story)): ?>
                <p class="gw-muted gw-threat-note">This is a roundup of several events, so its CVEs are listed but do not raise its priority.</p>
            <?php endif; ?>
        </section>

        <?php if ($canManage && $model->status !== ThreatTriage::STATUS_DISMISSED): ?>
            <section class="gw-panel">
                <h2 class="gw-panel__title"><i class="bi bi-sticky" aria-hidden="true"></i> Decide with a note</h2>
                <?= Html::beginForm(['dismiss', 'id' => $model->id], 'post', ['id' => 'threat-decide']) ?>
                    <div class="mb-3 gw-field">
                        <label class="form-label gw-field__label" for="threat-note">Note</label>
                        <?= Html::textarea('note', '', ['id' => 'threat-note', 'class' => 'form-control', 'rows' => 2, 'maxlength' => 500, 'placeholder' => 'For example: we do not run this product']) ?>
                    </div>
                    <div class="gw-threat-decide">
                        <?php if ($model->status !== ThreatTriage::STATUS_RELEVANT): ?>
                            <?= Html::submitButton('Relevant', ['class' => 'btn btn-outline-secondary btn-sm gw-btn', 'formaction' => yii\helpers\Url::to(['relevant', 'id' => $model->id])]) ?>
                        <?php endif; ?>
                        <?= Html::submitButton('Dismiss', ['class' => 'btn btn-outline-secondary btn-sm gw-btn']) ?>
                    </div>
                <?= Html::endForm() ?>
            </section>
        <?php endif; ?>

        <section class="gw-panel">
            <h2 class="gw-panel__title">Story</h2>
            <dl class="gw-threat-facts">
                <dt>First reported</dt><dd><?= $fmt->asDatetime($story->first_seen_at) ?></dd>
                <dt>Latest</dt><dd><?= $fmt->asDatetime($story->last_seen_at) ?></dd>
                <dt>Sources</dt><dd><?= (int) $story->source_count ?></dd>
                <?php if ($model->resolved_at): ?>
                    <dt>Decided</dt><dd><?= $fmt->asDatetime($model->resolved_at) ?> by <?= Html::encode($model->resolver->full_name ?? 'system') ?></dd>
                <?php endif; ?>
            </dl>
        </section>

        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-clock-history" aria-hidden="true"></i> History</h2>
            <?= AuditHistory::widget(['entityType' => ThreatTriage::entityType(), 'entityId' => $model->id]) ?>
        </section>
    </div>
</div>
