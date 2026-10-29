<?php

namespace tests;

use app\models\Lookup;
use app\models\Setting;
use app\models\User;
use app\modules\cloud\components\CloudChecks;
use app\modules\inventory\components\DeviceChecks;
use app\modules\supplier\components\SupplierChecks;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\db\Transaction;

/**
 * Base class for tests that touch the database: every test runs inside a
 * transaction that is rolled back afterwards (and per-request caches are reset).
 */
abstract class DbTestCase extends TestCase
{
    private ?Transaction $tx = null;

    protected function setUp(): void
    {
        parent::setUp();
        DeviceChecks::reset();
        CloudChecks::reset();
        SupplierChecks::reset();
        Setting::reset();
        $this->tx = Yii::$app->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->tx?->rollBack();
        DeviceChecks::reset();
        CloudChecks::reset();
        SupplierChecks::reset();
        Setting::reset();
        parent::tearDown();
    }

    protected function lookupId(string $type): int
    {
        $id = Lookup::find()->select('id')->where(['type' => $type])->scalar();
        if (!$id) {
            $l = new Lookup(['type' => $type, 'name' => 'Test ' . $type, 'is_active' => true]);
            $l->save(false);
            $id = $l->id;
        }
        return (int) $id;
    }

    protected function userId(): int
    {
        $id = User::find()->select('id')->scalar();
        if (!$id) {
            $id = User::createUser('testuser', 'test@example.com', 'Test User', 'Test!Password123', 'analyst', false)->id;
        }
        return (int) $id;
    }
}
