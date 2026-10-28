<?php

namespace app\modules\dashboard;

use app\modules\dashboard\widgets;

/**
 * Dashboard module. The dashboard is assembled from widget classes listed
 * below; add a new tile by creating a KpiWidget/PanelWidget subclass and
 * appending it here (or override via config/web.php module config).
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\dashboard\controllers';

    /** KPI tiles (top row), each a widgets\KpiWidget subclass or config array */
    public array $kpis = [
        widgets\OpenRisksKpi::class,
        widgets\HighResidualRisksKpi::class,
        widgets\OpenIncidentsKpi::class,
        widgets\RegulatorOverdueKpi::class,
        widgets\PendingDecisionsKpi::class,
        widgets\ActiveDevicesKpi::class,
        widgets\DeviceComplianceKpi::class,
        widgets\CloudAssetsKpi::class,
        widgets\CloudComplianceKpi::class,
        widgets\OverdueReviewsKpi::class,
    ];

    /** Panels below the KPIs: class + bootstrap column span */
    public array $panels = [
        ['class' => widgets\ResidualHeatmapPanel::class, 'span' => 'col-xxl-5 col-xl-6'],
        ['class' => widgets\IncidentTrendPanel::class, 'span' => 'col-xxl-7 col-xl-6'],
        ['class' => widgets\NeedsAttentionPanel::class, 'span' => 'col-xxl-7 col-xl-6'],
        ['class' => widgets\DevicePosturePanel::class, 'span' => 'col-xxl-5 col-xl-6'],
        ['class' => widgets\CloudPosturePanel::class, 'span' => 'col-12'],
        ['class' => widgets\ControlsPanel::class, 'span' => 'col-12'],
        ['class' => widgets\AttackPanel::class, 'span' => 'col-12'],
        ['class' => widgets\RecentActivityPanel::class, 'span' => 'col-12'],
    ];
}
