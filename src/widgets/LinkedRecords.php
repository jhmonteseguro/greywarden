<?php

namespace app\widgets;

use app\models\DomainRecord;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * Compact list of related records for view pages.
 *
 * Markup: section.gw-linked > h3.gw-linked__title + ul.gw-linked__list > li.gw-linked__item
 */
class LinkedRecords extends Widget
{
    public string $title;
    public string $icon = 'link-45deg';
    /** @var DomainRecord[] */
    public array $models = [];
    /** callable(DomainRecord): string  extra HTML (badges) per item */
    public $extra = null;
    public string $emptyText = 'None linked.';

    public function run(): string
    {
        $items = [];
        foreach ($this->models as $model) {
            $html = Html::a(Html::encode($model->getReferenceValue()), $model->getViewUrl(), ['class' => 'gw-ref'])
                . ' <span class="gw-linked__label">' . Html::encode($model->getTitleValue()) . '</span>';
            if ($model->getIsArchived()) {
                $html .= ' ' . \app\helpers\Badge::flag('archived', 'Archived');
            }
            if ($this->extra) {
                $html .= ' <span class="gw-linked__meta">' . call_user_func($this->extra, $model) . '</span>';
            }
            $items[] = Html::tag('li', $html, ['class' => 'gw-linked__item']);
        }
        $body = $items
            ? Html::tag('ul', implode("\n", $items), ['class' => 'gw-linked__list'])
            : Html::tag('p', Html::encode($this->emptyText), ['class' => 'gw-empty gw-linked__empty']);

        return Html::tag('section',
            Html::tag('h3', '<i class="bi bi-' . $this->icon . '" aria-hidden="true"></i> ' . Html::encode($this->title)
                . ' <span class="gw-linked__count">' . count($this->models) . '</span>', ['class' => 'gw-linked__title'])
            . $body,
            ['class' => 'gw-linked']
        );
    }
}
