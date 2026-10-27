<?php

namespace app\widgets;

use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * Index page toolbar: quick filter pills + free-text search box.
 *
 * Markup: div.gw-toolbar > nav.gw-quickfilters > a.gw-quickfilter(.is-active)
 *                        > form.gw-toolbar__search
 */
class QuickFilters extends Widget
{
    /** key => label */
    public array $filters = [];
    public ?string $current = null;
    public string $param = 'filter';
    public ?string $query = null;
    public string $placeholder = 'Search…';
    /** Optional counts: key => int */
    public array $counts = [];

    public function run(): string
    {
        $links = [];
        foreach ($this->filters as $key => $label) {
            $active = (string) $this->current === (string) $key;
            $text = Html::encode($label);
            if (isset($this->counts[$key])) {
                $text .= ' ' . Html::tag('span', (int) $this->counts[$key], ['class' => 'gw-quickfilter__count']);
            }
            $links[] = Html::a($text, Url::current([$this->param => $key, 'page' => null]), [
                'class' => 'gw-quickfilter' . ($active ? ' is-active' : ''),
                'aria-current' => $active ? 'page' : null,
            ]);
        }

        $form = Html::beginForm(Url::current(['q' => null, 'page' => null]), 'get', ['class' => 'gw-toolbar__search', 'role' => 'search'])
            . Html::hiddenInput($this->param, $this->current)
            . '<div class="input-group input-group-sm">'
            . '<span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>'
            . Html::textInput('q', $this->query, ['class' => 'form-control', 'placeholder' => $this->placeholder, 'aria-label' => 'Search'])
            . Html::submitButton('Search', ['class' => 'btn btn-outline-secondary'])
            . '</div>'
            . Html::endForm();

        return Html::tag('div',
            Html::tag('nav', implode("\n", $links), ['class' => 'gw-quickfilters', 'aria-label' => 'Quick filters']) . $form,
            ['class' => 'gw-toolbar']
        );
    }
}
