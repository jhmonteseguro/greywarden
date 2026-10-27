<?php

namespace app\widgets\grid;

use yii\helpers\Html;

/**
 * Renders a record reference (RSK-0001) as a link to its view page.
 */
class ReferenceColumn extends DataColumn
{
    public $format = 'raw';
    public $contentOptions = ['class' => 'gw-col-ref'];
    public $headerOptions = ['class' => 'gw-col-ref'];

    protected function renderDataCellContent($model, $key, $index): string
    {
        $value = (string) $model->{$this->attribute};
        $html = Html::a(Html::encode($value), $model->getViewUrl(), ['class' => 'gw-ref', 'data-pjax' => 0]);
        if ($model->getIsArchived()) {
            $html .= ' ' . \app\helpers\Badge::flag('archived', 'Archived');
        }
        return $html;
    }
}
