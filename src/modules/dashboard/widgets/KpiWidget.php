<?php

namespace app\modules\dashboard\widgets;

use yii\base\Widget;

/**
 * Base class for a dashboard KPI tile. Subclasses implement compute().
 *
 * Markup (views/kpi.php):
 *   a.gw-kpi.gw-kpi--{tone} > .gw-kpi__head(.gw-kpi__icon, .gw-kpi__label)
 *                           > .gw-kpi__value(.gw-kpi__unit)
 *                           > ul.gw-kpi__breakdown > li.gw-kpi__breakdown-item--{tone}
 *                           > .gw-kpi__caption
 */
abstract class KpiWidget extends Widget
{
    public string $label = '';
    public string $icon = 'activity';

    /**
     * @return array{value: int|float|string, unit?: string, tone?: string, url?: array|string|null,
     *               caption?: string, breakdown?: array<array{label: string, value: int|string, tone?: string, url?: array|string|null}>}
     *         A breakdown item's url only renders when the tile itself has no url (links cannot nest).
     */
    abstract protected function compute(): array;

    public function run(): string
    {
        $data = $this->compute() + ['unit' => '', 'tone' => 'neutral', 'url' => null, 'caption' => '', 'breakdown' => []];
        return $this->render('kpi', ['label' => $this->label, 'icon' => $this->icon, 'data' => $data, 'id' => $this->getId()]);
    }
}
