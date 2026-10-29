<?php

namespace app\modules\supplier\components;

use app\modules\control\models\Control;

/**
 * The supplier checks in force: supplier-check controls from the Control
 * Register that are implementing or implemented and not archived.
 *
 * Loaded once per request; Control::afterSave() calls reset().
 */
final class SupplierChecks
{
    /** @var SupplierCheck[]|null */
    private static ?array $cache = null;

    /** @return SupplierCheck[] control id => check, in reference order */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $controls = Control::find()->active()->inForce()->supplierChecks()->orderBy('control.reference')->all();
        self::$cache = [];
        foreach ($controls as $c) {
            self::$cache[$c->id] = self::fromControl($c);
        }
        return self::$cache;
    }

    /** @return SupplierCheck[] checks recorded per supplier (pass / fail) */
    public static function manual(): array
    {
        return array_filter(self::all(), static fn(SupplierCheck $c) => $c->isManual());
    }

    public static function find(int $id): ?SupplierCheck
    {
        return self::all()[$id] ?? null;
    }

    public static function fromControl(Control $c): SupplierCheck
    {
        return new SupplierCheck((int) $c->id, $c->reference, $c->title, (string) $c->supplier_check, $c->getSupplierTiers());
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
