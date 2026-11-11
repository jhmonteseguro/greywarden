<?php
/**
 * My work.
 * @var yii\web\View $this
 * @var array $groups components/MyWork::groups()
 * @var int $later open actions due later or with no date
 * @var int $urgent overdue items
 */

use app\components\Rbac;
use app\models\DomainRecord;
use yii\helpers\Html;

$this->title = 'My work';
$this->params['breadcrumbs'][] = $this->title;
$this->params['subtitle'] = $groups
    ? Html::encode(sprintf('%d overdue · %d in all', $urgent, array_sum(array_column($groups, 'count'))))
    : '';
$this->params['actions'] = Rbac::canManage() ? Html::a('<i class="bi bi-plus-lg"></i> New action', ['/action/action/create'], ['class' => 'btn btn-primary gw-btn gw-btn--create']) : '';
?>
<?php if (!$groups): ?>
    <section class="gw-panel">
        <p class="gw-empty mb-0"><i class="bi bi-check2-circle"></i> Nothing is overdue or due soon for you: no actions, reviews, tests or incidents need you right now.</p>
    </section>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($groups as $group): ?>
            <div class="col-xl-6">
                <section class="gw-panel gw-work<?= $group['urgent'] ? ' gw-work--urgent' : '' ?>" id="work-<?= Html::encode($group['key']) ?>">
                    <h2 class="gw-panel__title">
                        <i class="bi bi-<?= Html::encode($group['icon']) ?>" aria-hidden="true"></i> <?= Html::encode($group['label']) ?>
                        <span class="gw-panel__count"><?= (int) $group['count'] ?></span>
                    </h2>
                    <ul class="gw-linked__list">
                        <?php foreach ($group['items'] as $item): /** @var DomainRecord $item */ ?>
                            <li class="gw-linked__item">
                                <?= Html::a(Html::encode($item->getReferenceValue()), $item->getViewUrl(), ['class' => 'gw-ref']) ?>
                                <span class="gw-linked__label"><?= Html::encode($item->getTitleValue()) ?></span>
                                <span class="gw-linked__meta"><?= Html::encode(call_user_func($group['meta'], $item)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($group['count'] > count($group['items'])): ?>
                        <p class="mb-0 mt-2"><?= Html::a(sprintf('View all %d', $group['count']) . ' <i class="bi bi-chevron-right" aria-hidden="true"></i>', $group['url'], ['class' => 'gw-attention__more']) ?></p>
                    <?php endif; ?>
                </section>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($later > 0): ?>
    <p class="gw-muted mt-3"><?= Html::a(sprintf('%d more open action%s', $later, $later === 1 ? '' : 's'), ['/action/action/index', 'filter' => 'mine']) ?> due later or with no due date.</p>
<?php endif; ?>
