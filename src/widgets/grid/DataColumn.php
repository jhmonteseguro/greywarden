<?php

namespace app\widgets\grid;

use yii\helpers\Html;

/**
 * DataColumn using Bootstrap 5 small inputs/selects in the filter row.
 * Date/time columns get .gw-col-date (mono, no wrap) and numeric columns
 * .gw-col-num (right-aligned, tabular figures) for the table styling.
 */
class DataColumn extends \yii\grid\DataColumn
{
    private const DATE_FORMATS = ['date', 'datetime', 'time', 'relativeTime'];
    private const NUMBER_FORMATS = ['integer', 'decimal', 'percent', 'currency', 'shortSize', 'size'];

    public function init(): void
    {
        parent::init();
        $format = is_array($this->format) ? ($this->format[0] ?? null) : $this->format;
        $type = in_array($format, self::DATE_FORMATS, true) ? 'gw-col-date' : (in_array($format, self::NUMBER_FORMATS, true) ? 'gw-col-num' : null);
        if ($type !== null) {
            Html::addCssClass($this->contentOptions, $type);
            Html::addCssClass($this->headerOptions, $type);
        }
        $class = is_array($this->filter) ? 'form-select form-select-sm' : 'form-control form-control-sm';
        Html::addCssClass($this->filterInputOptions, $class);
        Html::removeCssClass($this->filterInputOptions, is_array($this->filter) ? 'form-control' : 'form-select');
    }
}
