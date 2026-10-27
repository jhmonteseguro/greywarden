<?php

namespace app\modules\inventory\components;

use app\modules\control\models\Control;

/**
 * The device checks in force: device-check controls from the Control Register
 * that are implementing or implemented and not archived.
 *
 * Loaded once per request; Control::afterSave() calls reset().
 */
final class DeviceChecks
{
    /** @var DeviceCheck[]|null */
    private static ?array $cache = null;

    /** @return DeviceCheck[] control id => check, in reference order */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $controls = Control::find()->active()->inForce()->deviceChecks()->with('mitigations')->orderBy('control.reference')->all();
        self::$cache = [];
        foreach ($controls as $c) {
            self::$cache[$c->id] = self::fromControl($c);
        }
        return self::$cache;
    }

    /** @return DeviceCheck[] checks recorded per device (pass / fail) */
    public static function manual(): array
    {
        return array_filter(self::all(), static fn(DeviceCheck $c) => !$c->isPatchAge());
    }

    /** @return DeviceCheck[] checks measured from the device's last patch date */
    public static function patchAge(): array
    {
        return array_filter(self::all(), static fn(DeviceCheck $c) => $c->isPatchAge());
    }

    public static function find(int $id): ?DeviceCheck
    {
        return self::all()[$id] ?? null;
    }

    public static function fromControl(Control $c): DeviceCheck
    {
        return new DeviceCheck(
            (int) $c->id,
            $c->reference,
            $c->title,
            $c->device_check === Control::CHECK_PATCH_AGE ? DeviceCheck::PATCH_AGE : DeviceCheck::MANUAL,
            $c->getDeviceTypes(),
            $c->device_ownership ?: Control::OWNERSHIP_ALL,
            (int) ($c->check_max_age_days ?: Control::defaultPatchMaxAgeDays()),
            array_map(static fn($m) => $m->external_id, $c->mitigations),
        );
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
