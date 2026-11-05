<?php
/**
 * Threat actors: who is in the news, and how exposed this organisation is to what they do.
 *
 * @var yii\web\View $this
 * @var app\modules\threat\models\ActorSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var array $counts filter => count
 * @var array<int, array{total:int, addressed:int, gaps:int}> $summaries group id => mitigation summary
 * @var array|null $release
 */

use app\modules\attack\models\AttackGroup;
use app\modules\threat\models\ActorSearch;
use app\widgets\GridView;
use app\widgets\QuickFilters;
use yii\helpers\Html;
use yii\helpers\StringHelper;

$this->title = 'Threat Actors';
$this->params['breadcrumbs'][] = 'Threat Intel';
$this->params['breadcrumbs'][] = 'Threat Actors';
$this->params['subtitle'] = 'The groups MITRE ATT&CK tracks, with those in your news first';
$fmt = Yii::$app->formatter;
$gaps = static function (AttackGroup $g) use ($summaries): string {
    $summary = $summaries[$g->id] ?? null;
    return $summary && $summary['total'] ? sprintf('%d of %d', $summary['gaps'], $summary['total']) : '';
};
?>
<?= QuickFilters::widget([
    'filters' => ActorSearch::FILTERS,
    'current' => $searchModel->filter,
    'query' => $searchModel->q,
    'counts' => $counts,
    'placeholder' => 'Search name or alias…',
]) ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'emptyText' => $searchModel->filter === 'named' ? 'No threat actor is named in your news yet.' : 'No threat actors match.',
    'columns' => [
        [
            'attribute' => 'name',
            'label' => 'Actor',
            'format' => 'raw',
            'contentOptions' => ['class' => 'gw-col-title gw-threat-col-story'],
            'value' => static function (AttackGroup $g) use ($gaps, $fmt) {
                $aliases = $g->getAliasList();
                $gap = $gaps($g);
                // The other columns are hidden on phones, so the row says the same there
                $inline = array_filter([$gap !== '' ? "$gap mitigations missing" : null, $g->story_count ? sprintf('%d in the news', $g->story_count) : null]);
                $meta = Html::encode($g->external_id) . ($inline ? '<span class="gw-threat-when-inline"> · ' . Html::encode(implode(' · ', $inline)) . '</span>' : '');
                return '<div class="gw-threat-row">' . Html::a(Html::encode($g->name), $g->getViewUrl(), ['class' => 'gw-threat-row__title'])
                    . ($aliases ? '<div class="gw-threat-row__summary">' . Html::encode(StringHelper::truncate(implode(', ', $aliases), 110)) . '</div>' : '')
                    . '<div class="gw-muted gw-intel-when">' . $meta . '</div></div>';
            },
        ],
        [
            'attribute' => 'technique_count',
            'label' => 'Techniques',
            'headerOptions' => ['class' => 'gw-threat-col-aux text-end'],
            'contentOptions' => ['class' => 'gw-threat-col-aux text-end gw-mono'],
            'value' => static fn(AttackGroup $g) => (int) $g->technique_count,
        ],
        [
            'label' => 'Mitigation gaps',
            'headerOptions' => ['class' => 'gw-threat-col-aux text-end', 'title' => 'Mitigations MITRE recommends against this group with no control or approved decision'],
            'contentOptions' => ['class' => 'gw-threat-col-aux text-end gw-mono'],
            'value' => $gaps,
        ],
        [
            'attribute' => 'last_named_at',
            'label' => 'In the news',
            'format' => 'raw',
            'headerOptions' => ['class' => 'gw-threat-col-aux'],
            'contentOptions' => ['class' => 'gw-threat-col-aux'],
            'value' => static fn(AttackGroup $g) => $g->story_count
                ? Html::encode(sprintf('%d stor%s', $g->story_count, (int) $g->story_count === 1 ? 'y' : 'ies')) . '<div class="gw-muted gw-intel-when">' . Html::encode($fmt->asRelativeTime($g->last_named_at)) . '</div>'
                : '<span class="gw-muted">—</span>',
        ],
    ],
]) ?>

<?= $this->render('@app/modules/attack/views/partials/_attribution', ['release' => $release]) ?>
