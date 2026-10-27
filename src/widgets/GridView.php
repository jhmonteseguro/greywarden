<?php

namespace app\widgets;

use yii\bootstrap5\LinkPager;

/**
 * Project-wide GridView defaults. Every index page uses this class so the
 * table markup/classes can be restyled in one place.
 *
 * Markup: div.gw-grid > div.table-responsive.gw-grid__body > table.table.gw-table
 *                      > div.gw-grid__footer > (div.gw-grid__summary, div.gw-grid__pager > ul.gw-pager)
 * The table header is sticky inside .gw-grid__body (see section 7 of greywarden.css).
 */
class GridView extends \yii\grid\GridView
{
    public $dataColumnClass = grid\DataColumn::class;
    public $options = ['class' => 'gw-grid'];
    public $tableOptions = ['class' => 'table table-hover align-middle gw-table'];
    public $headerRowOptions = ['class' => 'gw-table__head'];
    public $filterRowOptions = ['class' => 'gw-table__filters'];
    public $summaryOptions = ['class' => 'gw-grid__summary'];
    public $layout = "<div class=\"table-responsive gw-grid__body\">{items}</div>\n<div class=\"gw-grid__footer\">{summary}<div class=\"gw-grid__pager\">{pager}</div></div>";
    public $emptyTextOptions = ['class' => 'gw-empty'];
    public $pager = [
        'class' => LinkPager::class,
        'options' => ['class' => 'gw-pager'],
        'maxButtonCount' => 7,
        'firstPageLabel' => '«',
        'lastPageLabel' => '»',
        'prevPageLabel' => '‹',
        'nextPageLabel' => '›',
    ];

    public function init(): void
    {
        if ($this->emptyText === null) {
            $this->emptyText = 'No records match the current filters.';
        }
        parent::init();
    }
}
