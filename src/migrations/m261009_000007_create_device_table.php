<?php

use app\components\Migration;

class m261009_000007_create_device_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('device', [
            'id' => $this->primaryKey(),
            'asset_tag' => $this->string(50)->notNull()->unique(),
            'hostname' => $this->string(253)->notNull(),
            'device_type' => $this->string(20)->notNull(),
            'manufacturer' => $this->string(100)->null(),
            'model' => $this->string(100)->null(),
            'serial_number' => $this->string(100)->null(),
            'os' => $this->string(100)->null(),
            'os_version' => $this->string(64)->null(),
            'assigned_to' => $this->string(128)->null()->comment('Free-text person name'),
            'department_id' => $this->integer()->null(),
            'location_id' => $this->integer()->null(),
            'ip_addresses' => $this->string(512)->null()->comment('Comma separated'),
            'mac_address' => $this->string(17)->null(),
            'purchase_date' => $this->date()->null(),
            'warranty_expiry' => $this->date()->null(),
            'eol_date' => $this->date()->null(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'criticality' => $this->string(16)->notNull()->defaultValue('medium'),
            'data_classification' => $this->string(16)->notNull()->defaultValue('internal'),
            'disk_encrypted' => $this->boolean()->notNull()->defaultValue(false),
            'av_edr_installed' => $this->boolean()->notNull()->defaultValue(false),
            'mdm_enrolled' => $this->boolean()->notNull()->defaultValue(false),
            'firewall_enabled' => $this->boolean()->notNull()->defaultValue(false),
            'last_patched_at' => $this->date()->null(),
            'notes' => $this->text()->null(),
        ] + $this->archiveColumns() + $this->auditColumns(), $this->tableOptions());

        $this->createIndex('idx_device_hostname', 'device', 'hostname');
        $this->createIndex('idx_device_serial', 'device', 'serial_number');
        $this->createIndex('idx_device_status', 'device', 'status');
        $this->createIndex('idx_device_type', 'device', 'device_type');
        $this->createIndex('idx_device_warranty', 'device', 'warranty_expiry');
        $this->createIndex('idx_device_eol', 'device', 'eol_date');
        $this->createIndex('idx_device_archived', 'device', 'archived_at');
        $this->addForeignKey('fk_device_department', 'device', 'department_id', 'lookup', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_device_location', 'device', 'location_id', 'lookup', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('device', ['created_by', 'updated_by', 'archived_by']);
    }

    public function safeDown()
    {
        $this->dropTable('device');
    }
}
