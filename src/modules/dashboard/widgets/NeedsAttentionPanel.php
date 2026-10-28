<?php

namespace app\modules\dashboard\widgets;

use app\helpers\RiskMatrix;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\incident\models\Incident;
use app\modules\inventory\components\OsLifecycle;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use Yii;
use yii\db\Expression;
use yii\db\Query;

/** Items needing action across modules. */
class NeedsAttentionPanel extends PanelWidget
{
    public string $title = 'Needs attention';
    public string $icon = 'bell';
    public string $key = 'attention';

    protected function renderBody(): string
    {
        $limit = (int) (Yii::$app->params['dashboard.attentionLimit'] ?? 6);
        $today = date('Y-m-d');

        [$highMin] = RiskMatrix::scoreRange('high');
        // Open, live risks rated High or above that link to the control
        $highRisks = (new Query())->select(new Expression('1'))->from(['rc' => 'risk_control'])
            ->innerJoin(['r' => 'risk'], 'r.id = rc.risk_id')
            ->where('rc.control_id = control.id')
            ->andWhere(['r.archived_at' => null])->andWhere(['<>', 'r.status', Risk::STATUS_CLOSED])
            ->andWhere(['>=', new Expression('COALESCE(r.residual_score, r.inherent_score)'), $highMin]);

        $groups = [
            [
                'key' => 'critical-incidents',
                'label' => 'Open critical incidents',
                'icon' => 'fire',
                'query' => Incident::find()->active()->open()->andWhere(['severity' => Incident::SEVERITY_CRITICAL])->orderBy(['detected_at' => SORT_DESC]),
                'url' => ['/incident/incident/index', 'filter' => 'open', 'severity' => 'critical'],
                'meta' => static fn(Incident $i) => 'Detected ' . Yii::$app->formatter->asRelativeTime($i->detected_at),
            ],
            [
                'key' => 'regulator',
                'label' => 'Regulator notification overdue',
                'icon' => 'hourglass-bottom',
                'query' => Incident::find()->active()->regulatorOverdue()->orderBy(['detected_at' => SORT_ASC]),
                'url' => ['/incident/incident/index', 'filter' => 'regulator-overdue'],
                'meta' => static fn(Incident $i) => 'Deadline ' . Yii::$app->formatter->asRelativeTime($i->getRegulatorDeadline()?->getTimestamp()),
            ],
            [
                'key' => 'risk-review',
                'label' => 'Overdue risk reviews',
                'icon' => 'calendar-x',
                'query' => Risk::find()->active()->reviewOverdue($today)->orderBy(['next_review_date' => SORT_ASC]),
                'url' => ['/risk/risk/index', 'filter' => 'review-overdue'],
                'meta' => static fn(Risk $r) => 'Review due ' . Yii::$app->formatter->asDate($r->next_review_date),
            ],
            [
                'key' => 'risk-treatment',
                'label' => 'Overdue risk treatments',
                'icon' => 'tools',
                'query' => Risk::find()->active()->treatmentOverdue($today)->orderBy(['target_treatment_date' => SORT_ASC]),
                'url' => ['/risk/risk/index', 'filter' => 'treatment-overdue'],
                'meta' => static fn(Risk $r) => 'Target ' . Yii::$app->formatter->asDate($r->target_treatment_date),
            ],
            [
                'key' => 'weak-controls',
                'label' => 'Ineffective controls on high risks',
                'icon' => 'shield-exclamation',
                'query' => Control::find()->active()->weakResult()->andWhere(['exists', $highRisks])
                    ->orderBy([new Expression("FIELD(control.effectiveness, 'ineffective','partial')"), 'control.reference' => SORT_ASC]),
                'url' => ['/control/control/index', 'filter' => 'weak'],
                'meta' => static fn(Control $c) => $c->getEffectivenessLabel() . ($c->last_tested_on ? ' · tested ' . Yii::$app->formatter->asDate($c->last_tested_on) : ''),
            ],
            [
                'key' => 'control-tests',
                'label' => 'Control tests overdue',
                'icon' => 'clipboard-x',
                'query' => Control::find()->active()->testOverdue($today)->orderBy(['next_test_due' => SORT_ASC]),
                'url' => ['/control/control/index', 'filter' => 'test-overdue', 'sort' => 'next_test_due'],
                'meta' => static fn(Control $c) => 'Due ' . Yii::$app->formatter->asDate($c->next_test_due),
            ],
            [
                'key' => 'control-tests-soon',
                'label' => sprintf('Control tests due soon (%d days)', Control::testDueSoonDays()),
                'icon' => 'calendar-event',
                'query' => Control::find()->active()->testDueSoon($today)->orderBy(['next_test_due' => SORT_ASC]),
                'url' => ['/control/control/index', 'filter' => 'test-due-soon'],
                'meta' => static fn(Control $c) => 'Due ' . Yii::$app->formatter->asDate($c->next_test_due),
            ],
            [
                'key' => 'control-targets',
                'label' => 'Planned controls past target date',
                'icon' => 'hourglass-bottom',
                'query' => Control::find()->active()->targetOverdue($today)->orderBy(['target_date' => SORT_ASC]),
                'url' => ['/control/control/index', 'filter' => 'target-overdue'],
                'meta' => static fn(Control $c) => 'Target ' . Yii::$app->formatter->asDate($c->target_date),
            ],
            [
                'key' => 'non-compliant',
                'label' => 'Non-compliant devices',
                'icon' => 'shield-exclamation',
                'query' => Device::find()->active()->inService()->nonCompliant()->orderBy([new \yii\db\Expression("FIELD(criticality, 'critical','high','medium','low')"), 'asset_tag' => SORT_ASC]),
                'url' => ['/inventory/device/index', 'filter' => 'non-compliant'],
                'meta' => static fn(Device $d) => implode(', ', array_slice($d->getPosture()['failures'], 0, 2)),
            ],
            [
                'key' => 'not-assessed',
                'label' => 'Devices not assessed',
                'icon' => 'question-circle',
                'query' => Device::find()->active()->inService()->notAssessed()->orderBy([new Expression("FIELD(criticality, 'critical','high','medium','low')"), 'asset_tag' => SORT_ASC]),
                'url' => ['/inventory/device/index', 'filter' => 'not-assessed'],
                'meta' => static fn(Device $d) => count($d->getPosture()['unknown']) . ' check(s) without a result',
            ],
            [
                'key' => 'unsupported-os',
                'label' => 'Devices on an unsupported OS',
                'icon' => 'exclamation-octagon',
                'query' => Device::find()->active()->inService()->osSupport(OsLifecycle::UNSUPPORTED, $today)
                    ->orderBy([new Expression("FIELD(criticality, 'critical','high','medium','low')"), 'asset_tag' => SORT_ASC]),
                'url' => ['/inventory/device/index', 'filter' => 'unsupported-os'],
                'meta' => static fn(Device $d) => trim($d->os . ' ' . $d->os_version) . ' · ended ' . Yii::$app->formatter->asDate($d->getOsSupport()['endDate']),
            ],
            [
                'key' => 'stale-devices',
                'label' => sprintf('Devices not seen (%d+ days)', Device::staleDays()),
                'icon' => 'wifi-off',
                'query' => Device::find()->active()->inService()->stale($today)->orderBy(['last_seen_at' => SORT_DESC]),
                'url' => ['/inventory/device/index', 'filter' => 'stale', 'sort' => '-last_seen_at'],
                'meta' => static fn(Device $d) => 'Last seen ' . Yii::$app->formatter->asRelativeTime($d->last_seen_at),
            ],
            [
                'key' => 'cloud-exposed',
                'label' => 'Internet-facing cloud assets failing checks',
                'icon' => 'globe2',
                'query' => CloudAsset::find()->active()->inService()->internetFacing()->nonCompliant()
                    ->orderBy([new Expression("FIELD(cloud_asset.criticality, 'critical','high','medium','low')"), 'cloud_asset.reference' => SORT_ASC]),
                'url' => ['/cloud/asset/index', 'filter' => 'internet-facing'],
                'meta' => static fn(CloudAsset $a) => implode(', ', array_slice($a->getPosture()['failures'], 0, 2)),
            ],
            [
                'key' => 'cloud-non-compliant',
                'label' => 'Non-compliant cloud assets',
                'icon' => 'cloud-slash',
                'query' => CloudAsset::find()->active()->inService()->nonCompliant()
                    ->orderBy([new Expression("FIELD(cloud_asset.criticality, 'critical','high','medium','low')"), 'cloud_asset.reference' => SORT_ASC]),
                'url' => ['/cloud/asset/index', 'filter' => 'non-compliant'],
                'meta' => static fn(CloudAsset $a) => implode(', ', array_slice($a->getPosture()['failures'], 0, 2)),
            ],
            [
                'key' => 'cloud-accounts',
                'label' => 'Cloud accounts failing checks',
                'icon' => 'cloud-slash',
                'query' => CloudAccount::find()->active()->inService()->nonCompliant()
                    ->orderBy([new Expression("FIELD(cloud_account.criticality, 'critical','high','medium','low')"), 'cloud_account.reference' => SORT_ASC]),
                'url' => ['/cloud/account/index', 'filter' => 'non-compliant'],
                'meta' => static fn(CloudAccount $a) => implode(', ', array_slice($a->getPosture()['failures'], 0, 2)),
            ],
            [
                'key' => 'cloud-unsupported-os',
                'label' => 'Cloud instances on an unsupported OS',
                'icon' => 'exclamation-octagon',
                'query' => CloudAsset::find()->active()->inService()->osSupport(OsLifecycle::UNSUPPORTED, $today)
                    ->orderBy([new Expression("FIELD(cloud_asset.criticality, 'critical','high','medium','low')"), 'cloud_asset.reference' => SORT_ASC]),
                'url' => ['/cloud/asset/index', 'filter' => 'unsupported-os'],
                'meta' => static fn(CloudAsset $a) => trim($a->os . ' ' . $a->os_version) . ' · ended ' . Yii::$app->formatter->asDate($a->getOsSupport()['endDate']),
            ],
            [
                'key' => 'stale-cloud',
                'label' => sprintf('Cloud assets not seen (%d+ days)', CloudAsset::staleDays()),
                'icon' => 'cloud-slash',
                'query' => CloudAsset::find()->active()->inService()->stale($today)->orderBy(['cloud_asset.last_seen_at' => SORT_DESC]),
                'url' => ['/cloud/asset/index', 'filter' => 'stale', 'sort' => '-last_seen_at'],
                'meta' => static fn(CloudAsset $a) => 'Last seen ' . Yii::$app->formatter->asRelativeTime($a->last_seen_at),
            ],
            [
                'key' => 'warranty',
                'label' => sprintf('Warranties expiring (%d days)', Device::warrantyWarningDays()),
                'icon' => 'calendar-event',
                'query' => Device::find()->active()->warrantyExpiring($today)->orderBy(['warranty_expiry' => SORT_ASC]),
                'url' => ['/inventory/device/index', 'filter' => 'warranty'],
                'meta' => static fn(Device $d) => 'Expires ' . Yii::$app->formatter->asDate($d->warranty_expiry),
            ],
        ];

        foreach ($groups as &$group) {
            $group['total'] = (int) (clone $group['query'])->count();
            $group['items'] = $group['query']->limit($limit)->all();
        }
        unset($group);

        return $this->render('attention', ['groups' => $groups]);
    }
}
