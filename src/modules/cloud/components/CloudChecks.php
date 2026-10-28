<?php

namespace app\modules\cloud\components;

use app\modules\control\models\Control;

/**
 * The cloud checks in force: cloud-check controls from the Control Register
 * that are implementing or implemented and not archived.
 *
 * Loaded once per request; Control::afterSave() calls reset().
 */
final class CloudChecks
{
    /** @var CloudCheck[]|null */
    private static ?array $cache = null;

    /** @return CloudCheck[] control id => check, in reference order */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $controls = Control::find()->active()->inForce()->cloudChecks()->with('mitigations')->orderBy('control.reference')->all();
        self::$cache = [];
        foreach ($controls as $c) {
            self::$cache[$c->id] = self::fromControl($c);
        }
        return self::$cache;
    }

    /** @return CloudCheck[] checks that can apply to cloud assets */
    public static function forAssets(): array
    {
        return array_filter(self::all(), static fn(CloudCheck $c) => $c->assetTypes() !== []);
    }

    /** @return CloudCheck[] checks recorded on cloud accounts */
    public static function forAccounts(): array
    {
        return array_filter(self::all(), static fn(CloudCheck $c) => $c->appliesToAccounts());
    }

    /** @return CloudCheck[] manual checks recorded per asset (pass / fail) */
    public static function manualForAssets(): array
    {
        return array_filter(self::forAssets(), static fn(CloudCheck $c) => $c->isManual());
    }

    public static function find(int $id): ?CloudCheck
    {
        return self::all()[$id] ?? null;
    }

    public static function fromControl(Control $c): CloudCheck
    {
        $scope = $c->getCloudScope();
        return new CloudCheck(
            (int) $c->id,
            $c->reference,
            $c->title,
            match ($c->cloud_check) {
                Control::CHECK_OS_SUPPORT => CloudCheck::OS_SUPPORT,
                Control::CHECK_ENCRYPTION => CloudCheck::ENCRYPTION,
                default => CloudCheck::MANUAL,
            },
            $scope === null ? null : array_values(array_diff($scope, [CloudCatalogue::SCOPE_ACCOUNT])),
            $scope !== null && in_array(CloudCatalogue::SCOPE_ACCOUNT, $scope, true),
            array_map(static fn($m) => $m->external_id, $c->mitigations),
        );
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
