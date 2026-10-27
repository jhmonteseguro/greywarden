<?php

namespace app\modules\dashboard\widgets;

use app\components\Rbac;
use app\models\AuditLog;
use Yii;
use yii\helpers\Html;

/** Recent changes to domain records, from the audit log. */
class RecentActivityPanel extends PanelWidget
{
    public string $title = 'Recent activity';
    public string $icon = 'activity';
    public string $key = 'activity';

    protected function renderTools(): string
    {
        return Rbac::can(Rbac::PERM_AUDIT) ? Html::a('Full audit log', ['/admin/audit/index'], ['class' => 'gw-panel__link']) : '';
    }

    protected function renderBody(): string
    {
        $entries = AuditLog::find()
            ->where(['entity_type' => ['risk', 'decision', 'incident', 'device', 'blocklist']])
            ->orderBy(['id' => SORT_DESC])
            ->limit((int) (Yii::$app->params['dashboard.activityLimit'] ?? 12))
            ->all();
        return $this->render('activity', ['entries' => $entries]);
    }
}
