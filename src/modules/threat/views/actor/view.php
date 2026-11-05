<?php
/**
 * One threat actor: who it is, what the news says, and how this organisation's defences
 * stand against what it does.
 *
 * @var yii\web\View $this
 * @var app\modules\attack\models\AttackGroup $model
 * @var app\modules\threat\components\ActorProfile $profile
 * @var int $storyCount
 * @var app\modules\threat\models\ThreatTriage[] $stories the latest
 * @var array|null $release
 */

use app\helpers\Badge;
use app\modules\attack\components\AttackText;
use app\modules\attack\models\AttackGroup;
use app\modules\attack\widgets\MitigationCoverage;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\threat\components\ActorProfile;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = $model->name;
$this->params['breadcrumbs'][] = 'Threat Intel';
$this->params['breadcrumbs'][] = ['label' => 'Threat Actors', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->external_id;
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->external_id) . '</span>'
    . ($model->is_deprecated ? ' ' . Badge::flag('deprecated', 'Deprecated') : '');
$this->params['actions'] = Html::a('<i class="bi bi-box-arrow-up-right"></i> attack.mitre.org', $model->getMitreUrl(), [
    'class' => 'btn btn-outline-secondary gw-btn', 'target' => '_blank', 'rel' => 'noopener noreferrer',
]);

$fmt = Yii::$app->formatter;
$aliases = $model->getAliasList();
$replacement = $model->revoked_by ? AttackGroup::findByExternalId($model->revoked_by) : null;
$total = count($profile->techniques);
$covered = $profile->getCoveredCount();
$seen = $profile->getSeenCount();
$kpi = static fn(string $tone, string $anchor, string $label, string $icon, string $value, string $caption) => sprintf(
    '<div class="gw-kpis__item"><a class="gw-kpi gw-kpi--%s" href="#%s"><div class="gw-kpi__head"><span class="gw-kpi__label">%s</span><span class="gw-kpi__icon"><i class="bi bi-%s"></i></span></div><span class="gw-kpi__value">%s</span><span class="gw-kpi__caption">%s</span></a></div>',
    $tone, $anchor, Html::encode($label), $icon, Html::encode($value), Html::encode($caption));
?>
<?php if ($replacement): ?>
    <div class="alert alert-warning gw-callout" role="status">
        <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
        <span>MITRE has merged this group into <?= Html::a(Html::encode($replacement->name), $replacement->getViewUrl()) ?>.</span>
    </div>
<?php endif; ?>

<section class="gw-kpis gw-edge-kpis" aria-label="Key figures">
    <?= $kpi($storyCount ? 'info' : 'neutral', 'actor-news', 'In your news', 'newspaper', number_format($storyCount), $storyCount === 1 ? 'story names it' : 'stories name it') ?>
    <?= $kpi('neutral', 'actor-techniques', 'Techniques', 'crosshair', number_format($total), sprintf('%d with a mitigation planned or in place', $covered)) ?>
    <?= $kpi($profile->summary['gaps'] ? 'warning' : 'success', 'actor-mitigations', 'Mitigation gaps', 'shield-exclamation', number_format($profile->summary['gaps']), sprintf('of %d MITRE recommends', $profile->summary['total'])) ?>
    <?= $kpi($seen ? 'critical' : 'success', 'actor-techniques', 'Seen here', 'fire', number_format($seen), 'of its techniques are in your incidents or open risks') ?>
</section>

<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">About</h2>
            <div class="gw-attack-text"><?= AttackText::render($model->description) ?: '<p class="gw-null">No description.</p>' ?></div>
        </section>

        <section class="gw-panel" id="actor-mitigations">
            <h2 class="gw-panel__title"><i class="bi bi-shield-check" aria-hidden="true"></i> Mitigations against this actor <span class="gw-panel__count"><?= $profile->summary['total'] ?></span></h2>
            <p class="gw-muted gw-threat-note">A gap means no control or approved decision records the mitigation, so it may be in place but not mapped. Record mitigations on your controls for this to reflect your defences.</p>
            <?= MitigationCoverage::widget(['techniqueIds' => array_map(static fn($t) => $t->id, $profile->techniques), 'emptyText' => 'MITRE lists no techniques for this group.', 'maxTechniques' => 4]) ?>
        </section>

        <section class="gw-panel gw-panel--flush" id="actor-techniques">
            <div class="gw-panel__header gw-panel__header--flush">
                <h2 class="gw-panel__title"><i class="bi bi-crosshair" aria-hidden="true"></i> Techniques by tactic <span class="gw-panel__count"><?= $total ?></span></h2>
            </div>
            <?php if (!$total): ?>
                <p class="gw-empty gw-threat-flush-note">MITRE lists no techniques for this group.</p>
            <?php endif; ?>
            <?php foreach ($profile->byTactic() as $entry): ?>
                <?php $exposed = $profile->exposedCount($entry['techniques']); ?>
                <details class="gw-threat-tactic">
                    <summary class="gw-threat-tactic__summary">
                        <span class="gw-threat-tactic__name"><?= Html::encode($entry['tactic']->name) ?></span>
                        <span class="gw-muted gw-threat-tactic__count"><?= count($entry['techniques']) ?> technique<?= count($entry['techniques']) === 1 ? '' : 's' ?><?php if ($exposed): ?> · <span class="gw-threat-tactic__exposed"><?= $exposed ?> exposed</span><?php endif; ?></span>
                    </summary>
                    <div class="table-responsive">
                        <table class="table gw-table gw-threat-techniques">
                            <thead><tr><th scope="col">Technique</th><th scope="col">Best mitigation</th><th scope="col" class="gw-threat-col-aux">Seen here</th></tr></thead>
                            <tbody>
                                <?php foreach ($entry['techniques'] as $technique): ?>
                                    <?php
                                    $state = $profile->states[$technique->external_id];
                                    $here = array_filter([
                                        isset($profile->incidents[$technique->id]) ? sprintf('%d incident%s', $profile->incidents[$technique->id], $profile->incidents[$technique->id] === 1 ? '' : 's') : null,
                                        isset($profile->risks[$technique->id]) ? sprintf('%d risk%s', $profile->risks[$technique->id], $profile->risks[$technique->id] === 1 ? '' : 's') : null,
                                    ]);
                                    ?>
                                    <tr>
                                        <td><?= TechniqueTags::tag($technique) ?></td>
                                        <td><?= $state === ActorProfile::STATE_UNMAPPED ? '<span class="gw-muted" title="MITRE lists no mitigation for this technique">None listed</span>' : MitigationCoverage::stateBadge($state) ?></td>
                                        <td class="gw-threat-col-aux"><?= $here ? Html::a(Html::encode(implode(', ', $here)), Url::to($technique->getViewUrl())) : '<span class="gw-muted">—</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endforeach; ?>
        </section>
    </div>

    <div class="col-xl-4">
        <?php if ($aliases): ?>
            <section class="gw-panel">
                <h2 class="gw-panel__title">Also known as <span class="gw-panel__count"><?= count($aliases) ?></span></h2>
                <p class="gw-threat-aliases"><?= Html::encode(implode(', ', $aliases)) ?></p>
            </section>
        <?php endif; ?>

        <section class="gw-panel" id="actor-news">
            <h2 class="gw-panel__title"><i class="bi bi-newspaper" aria-hidden="true"></i> In your news <span class="gw-panel__count"><?= $storyCount ?></span></h2>
            <?php if (!$stories): ?>
                <p class="gw-empty">No story names this group yet.</p>
            <?php else: ?>
                <ul class="gw-threat-brief">
                    <?php foreach ($stories as $triage): ?>
                        <li class="gw-threat-brief__item">
                            <?= Badge::render('threat-priority', $triage->priority, ucfirst($triage->priority)) ?>
                            <?= Html::a(Html::encode($triage->story->title), $triage->getViewUrl(), ['class' => 'gw-threat-brief__title']) ?>
                            <span class="gw-muted gw-threat-brief__when"><?= $fmt->asRelativeTime($triage->story->last_seen_at) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($storyCount > count($stories)): ?><p class="gw-muted gw-threat-brief__more">The latest <?= count($stories) ?> are shown.</p><?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?= $this->render('@app/modules/attack/views/partials/_attribution', ['release' => $release]) ?>
