<?php

namespace app\modules\dashboard\widgets;

use yii\base\Widget;
use yii\helpers\Html;

/**
 * Base class for dashboard panels (charts, lists). Subclasses implement renderBody().
 *
 * Markup: section.gw-panel.gw-dashboard-panel.gw-dashboard-panel--{key}
 *           > header.gw-panel__header > h2.gw-panel__title + .gw-panel__tools
 *           > .gw-panel__body
 */
abstract class PanelWidget extends Widget
{
    public string $title = '';
    public string $icon = 'grid';
    /** Short machine name used for the modifier class */
    public string $key = 'panel';

    abstract protected function renderBody(): string;

    /** Optional header tools (links, toggles) */
    protected function renderTools(): string
    {
        return '';
    }

    public function run(): string
    {
        $header = Html::tag('header',
            Html::tag('h2', '<i class="bi bi-' . Html::encode($this->icon) . '" aria-hidden="true"></i> ' . Html::encode($this->title), ['class' => 'gw-panel__title'])
            . Html::tag('div', $this->renderTools(), ['class' => 'gw-panel__tools']),
            ['class' => 'gw-panel__header']
        );
        return Html::tag('section', $header . Html::tag('div', $this->renderBody(), ['class' => 'gw-panel__body']), [
            'class' => 'gw-panel gw-dashboard-panel gw-dashboard-panel--' . $this->key,
        ]);
    }
}
