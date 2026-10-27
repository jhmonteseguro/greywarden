<?php

namespace app\widgets;

use app\components\Rbac;
use app\models\DomainRecord;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * Edit / Archive / Restore / Delete buttons for a record's view page,
 * shown according to the current user's permissions.
 */
class RecordActions extends Widget
{
    public DomainRecord $model;
    /** extra HTML prepended (e.g. module specific buttons) */
    public string $before = '';
    /** show the PDF button (the controller must allow its `pdf` action, see CrudController::actionPdf) */
    public bool $pdf = false;

    public function run(): string
    {
        $m = $this->model;
        $buttons = [$this->before];
        if ($this->pdf) {
            $buttons[] = Html::a('<i class="bi bi-file-earmark-pdf"></i> PDF', ['pdf', 'id' => $m->id], [
                'class' => 'btn btn-outline-secondary gw-btn gw-btn--pdf',
                'target' => '_blank',
                'rel' => 'noopener',
                'title' => 'Open a PDF of this record to print or send',
            ]);
        }
        if (Rbac::canManage()) {
            if (!$m->getIsArchived()) {
                $buttons[] = Html::a('<i class="bi bi-pencil-square"></i> Edit', ['update', 'id' => $m->id], ['class' => 'btn btn-primary gw-btn gw-btn--edit']);
                $buttons[] = Html::a('<i class="bi bi-archive"></i> Archive', ['archive', 'id' => $m->id], [
                    'class' => 'btn btn-outline-warning gw-btn gw-btn--archive',
                    'data-method' => 'post',
                    'data-confirm' => sprintf('Archive %s? It will be hidden from lists but can be restored.', $m->getReferenceValue()),
                ]);
            } else {
                $buttons[] = Html::a('<i class="bi bi-arrow-counterclockwise"></i> Restore', ['restore', 'id' => $m->id], [
                    'class' => 'btn btn-outline-success gw-btn gw-btn--restore',
                    'data-method' => 'post',
                ]);
            }
        }
        if (Rbac::canDelete() && $m->getIsArchived()) {
            $buttons[] = Html::a('<i class="bi bi-trash"></i> Delete permanently', ['delete', 'id' => $m->id], [
                'class' => 'btn btn-outline-danger gw-btn gw-btn--delete',
                'data-method' => 'post',
                'data-confirm' => sprintf('Permanently delete %s? This cannot be undone.', $m->getReferenceValue()),
            ]);
        }
        return implode("\n", array_filter($buttons));
    }
}
