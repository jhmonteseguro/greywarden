<?php

namespace app\components;

use app\modules\control\components\FrameworkCatalogue;
use Yii;

/**
 * Sidebar navigation definition. Add new modules here. The Compliance section
 * lists the frameworks switched on in Admin › Settings.
 * Each item: label, icon (bootstrap-icons name), url, permission, active (route prefix list),
 * activeParams (optional: query params that must also match, e.g. ['framework' => 'iso27001']).
 */
final class Navigation
{
    public static function sections(): array
    {
        return [
            [
                'label' => 'Operations',
                'items' => [
                    ['label' => 'Dashboard', 'icon' => 'speedometer2', 'url' => ['/dashboard/default/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['dashboard/']],
                    ['label' => 'Risk Register', 'icon' => 'exclamation-triangle', 'url' => ['/risk/risk/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['risk/']],
                    ['label' => 'Control Register', 'icon' => 'shield-shaded', 'url' => ['/control/control/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['control/control/']],
                    ['label' => 'Decision Log', 'icon' => 'journal-check', 'url' => ['/decision/decision/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['decision/']],
                    ['label' => 'Incident Log', 'icon' => 'fire', 'url' => ['/incident/incident/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['incident/']],
                    ['label' => 'Device Inventory', 'icon' => 'pc-display', 'url' => ['/inventory/device/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['inventory/']],
                ],
            ],
            [
                'label' => 'Compliance',
                'items' => array_map(static fn(string $framework) => [
                    'label' => FrameworkCatalogue::navLabel($framework), 'icon' => FrameworkCatalogue::icon($framework),
                    'url' => ['/control/framework/view', 'framework' => FrameworkCatalogue::slug($framework)], 'permission' => Rbac::PERM_VIEW,
                    'active' => ['control/framework/view/'], 'activeParams' => ['framework' => FrameworkCatalogue::slug($framework)],
                ], FrameworkCatalogue::enabled()),
            ],
            [
                'label' => 'Threat Intel',
                'items' => [
                    ['label' => 'ATT&CK Matrix', 'icon' => 'crosshair', 'url' => ['/attack/technique/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['attack/technique/']],
                    ['label' => 'Mitigations', 'icon' => 'shield-check', 'url' => ['/attack/mitigation/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['attack/mitigation/']],
                    ['label' => 'IP Investigation', 'icon' => 'radar', 'url' => ['/intel/investigate/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['intel/investigate/']],
                    ['label' => 'Blocklist', 'icon' => 'slash-circle', 'url' => ['/intel/blocklist/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['intel/blocklist/']],
                    ['label' => 'Intel Sources', 'icon' => 'broadcast', 'url' => ['/intel/source/index'], 'permission' => Rbac::PERM_VIEW, 'active' => ['intel/source/']],
                ],
            ],
            [
                'label' => 'Admin',
                'items' => [
                    ['label' => 'Users', 'icon' => 'people', 'url' => ['/admin/user/index'], 'permission' => Rbac::PERM_USERS, 'active' => ['admin/user/']],
                    ['label' => 'Audit Log', 'icon' => 'clock-history', 'url' => ['/admin/audit/index'], 'permission' => Rbac::PERM_AUDIT, 'active' => ['admin/audit/']],
                    ['label' => 'Lookups', 'icon' => 'list-ul', 'url' => ['/admin/lookup/index'], 'permission' => Rbac::PERM_LOOKUPS, 'active' => ['admin/lookup/']],
                    ['label' => 'Settings', 'icon' => 'sliders', 'url' => ['/admin/settings/index'], 'permission' => Rbac::PERM_SETTINGS, 'active' => ['admin/settings/']],
                ],
            ],
        ];
    }

    /** Sections filtered by the current user's permissions, with `isActive` resolved. */
    public static function visibleSections(): array
    {
        $route = Yii::$app->controller ? Yii::$app->controller->getRoute() : '';
        $out = [];
        foreach (self::sections() as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                if (!empty($item['permission']) && !Rbac::can($item['permission'])) {
                    continue;
                }
                $item['isActive'] = false;
                foreach ($item['active'] ?? [] as $prefix) {
                    if (str_starts_with($route . '/', $prefix)) {
                        $item['isActive'] = true;
                    }
                }
                foreach ($item['activeParams'] ?? [] as $param => $value) {
                    $item['isActive'] = $item['isActive'] && Yii::$app->request->get($param) === $value;
                }
                $items[] = $item;
            }
            if ($items) {
                $section['items'] = $items;
                $out[] = $section;
            }
        }
        return $out;
    }
}
