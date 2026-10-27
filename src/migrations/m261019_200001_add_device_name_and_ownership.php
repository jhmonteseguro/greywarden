<?php

use app\components\Migration;

/**
 * Devices get a display name (phones and BYOD devices often have no hostname
 * we control, e.g. "Jane's iPhone"), the hostname becomes optional, and
 * ownership records whether the device is company-owned or personal (BYOD).
 */
class m261019_200001_add_device_name_and_ownership extends Migration
{
    public function safeUp()
    {
        $this->addColumn('device', 'name', $this->string(128)->null()->after('asset_tag'));
        $this->update('device', ['name' => new \yii\db\Expression('[[hostname]]')]);
        $this->alterColumn('device', 'name', $this->string(128)->notNull());
        $this->alterColumn('device', 'hostname', $this->string(253)->null());
        $this->addColumn('device', 'ownership', $this->string(16)->notNull()->defaultValue('company')->after('device_type')->comment('company | byod'));
        $this->createIndex('idx_device_name', 'device', 'name');
        $this->createIndex('idx_device_ownership', 'device', 'ownership');
    }

    public function safeDown()
    {
        $this->dropIndex('idx_device_ownership', 'device');
        $this->dropIndex('idx_device_name', 'device');
        $this->dropColumn('device', 'ownership');
        // Devices without a hostname fall back to their name (it may not be a valid hostname)
        $this->update('device', ['hostname' => new \yii\db\Expression('[[name]]')], ['hostname' => null]);
        $this->alterColumn('device', 'hostname', $this->string(253)->notNull());
        $this->dropColumn('device', 'name');
    }
}
