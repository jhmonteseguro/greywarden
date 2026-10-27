<?php

namespace app\widgets;

/**
 * Project-wide DetailView defaults: a two-column definition table.
 */
class DetailView extends \yii\widgets\DetailView
{
    public $options = ['class' => 'table gw-detail'];
    public $template = '<tr><th{captionOptions}>{label}</th><td{contentOptions}>{value}</td></tr>';
}
