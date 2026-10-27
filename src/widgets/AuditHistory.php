<?php

namespace app\widgets;

use app\models\AuditLog;
use yii\base\Widget;

/**
 * "History" tab content: audit entries for a record, newest first.
 * View file: widgets/views/audit-history.php
 */
class AuditHistory extends Widget
{
    public string $entityType;
    public int $entityId;
    public int $limit = 200;
    public bool $showEntity = false;
    /** @var AuditLog[]|null pre-loaded entries */
    public ?array $entries = null;

    public function run(): string
    {
        $entries = $this->entries ?? AuditLog::find()
            ->where(['entity_type' => $this->entityType, 'entity_id' => $this->entityId])
            ->orderBy(['id' => SORT_DESC])->limit($this->limit)->all();
        return $this->render('audit-history', ['entries' => $entries, 'showEntity' => $this->showEntity]);
    }
}
