<?php

use app\components\Migration;

/**
 * Control Register: controls, their effectiveness tests, ISO 27001 / Cyber
 * Essentials requirements, device check results and links to risks,
 * decisions, incidents and ATT&CK mitigations.
 */
class m261020_000001_create_control_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('control', array_merge([
            'id' => $this->primaryKey(),
            'reference' => $this->string(20)->notNull()->unique(),
            'title' => $this->string(255)->notNull(),
            'description' => $this->text()->null()->comment('What the control does'),
            'implementation' => $this->text()->null()->comment('How it is implemented'),
            'evidence' => $this->text()->null()->comment('Where the evidence lives'),
            'owner_id' => $this->integer()->null(),
            'control_type' => $this->string(20)->notNull()->defaultValue('preventive'),
            'nature' => $this->string(20)->notNull()->defaultValue('technical'),
            'status' => $this->string(20)->notNull()->defaultValue('planned'),
            'target_date' => $this->date()->null(),
            'implemented_on' => $this->date()->null(),
            'test_frequency_months' => $this->smallInteger()->null(),
            'last_tested_on' => $this->date()->null()->comment('Derived from control_test'),
            'effectiveness' => $this->string(20)->notNull()->defaultValue('not_tested')->comment('Derived from control_test'),
            'next_test_due' => $this->date()->null()->comment('Derived'),
            'device_check' => $this->string(20)->null()->comment('null | manual | patch_age'),
            'device_types' => $this->string(255)->null()->comment('Comma separated device types; null = all'),
            'device_ownership' => $this->string(20)->notNull()->defaultValue('all'),
            'check_max_age_days' => $this->smallInteger()->null(),
        ], $this->archiveColumns(), $this->auditColumns()), $this->tableOptions());
        $this->createIndex('idx_control_status', 'control', 'status');
        $this->createIndex('idx_control_next_test_due', 'control', 'next_test_due');
        $this->createIndex('idx_control_device_check', 'control', 'device_check');
        $this->addForeignKey('fk_control_owner_id', 'control', 'owner_id', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('control', ['created_by', 'updated_by', 'archived_by']);

        $this->createTable('control_test', [
            'id' => $this->primaryKey(),
            'control_id' => $this->integer()->notNull(),
            'tested_on' => $this->date()->notNull(),
            'result' => $this->string(20)->notNull(),
            'tested_by_id' => $this->integer()->null(),
            'method' => $this->string(255)->null(),
            'notes' => $this->text()->null(),
            'evidence' => $this->string(512)->null(),
            'created_at' => $this->dateTime()->notNull(),
            'created_by' => $this->integer()->null(),
        ], $this->tableOptions());
        $this->createIndex('idx_control_test_control', 'control_test', ['control_id', 'tested_on']);
        $this->addForeignKey('fk_control_test_control_id', 'control_test', 'control_id', 'control', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_control_test_tested_by_id', 'control_test', 'tested_by_id', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('control_test', ['created_by']);

        $this->createTable('framework_requirement', [
            'id' => $this->primaryKey(),
            'framework' => $this->string(30)->notNull(),
            'external_id' => $this->string(20)->notNull(),
            'group_code' => $this->string(10)->notNull(),
            'group_name' => $this->string(100)->notNull(),
            'title' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'sort_order' => $this->integer()->notNull()->defaultValue(0),
            'applicable' => $this->boolean()->notNull()->defaultValue(true),
            'justification' => $this->text()->null()->comment('Statement of Applicability reason'),
            'updated_at' => $this->dateTime()->null(),
            'updated_by' => $this->integer()->null(),
        ], $this->tableOptions());
        $this->createIndex('ux_framework_requirement', 'framework_requirement', ['framework', 'external_id'], true);
        $this->addUserForeignKeys('framework_requirement', ['updated_by']);

        $this->createLinkTable('risk_control', 'risk_id', 'risk', 'control_id', 'control');
        $this->createLinkTable('decision_control', 'decision_id', 'decision', 'control_id', 'control');
        $this->createLinkTable('control_attack_mitigation', 'control_id', 'control', 'mitigation_id', 'attack_mitigation');
        $this->createLinkTable('control_framework_requirement', 'control_id', 'control', 'requirement_id', 'framework_requirement');

        // Incident links carry the control's role in the incident
        $this->createTable('incident_control', [
            'incident_id' => $this->integer()->notNull(),
            'control_id' => $this->integer()->notNull(),
            'role' => $this->string(20)->notNull()->comment('detected | failed | response'),
            'created_at' => $this->dateTime()->notNull(),
            'created_by' => $this->integer()->null(),
            'PRIMARY KEY ([[incident_id]], [[control_id]], [[role]])',
        ], $this->tableOptions());
        $this->createIndex('idx_incident_control_control_id', 'incident_control', 'control_id');
        $this->addForeignKey('fk_incident_control_incident_id', 'incident_control', 'incident_id', 'incident', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_incident_control_control_id', 'incident_control', 'control_id', 'control', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_incident_control_created_by', 'incident_control', 'created_by', 'user', 'id', 'SET NULL', 'CASCADE');

        // Result of a manual device check; no row = not assessed
        $this->createTable('device_control', [
            'device_id' => $this->integer()->notNull(),
            'control_id' => $this->integer()->notNull(),
            'result' => $this->string(10)->notNull()->comment('pass | fail'),
            'checked_on' => $this->date()->null(),
            'checked_by' => $this->integer()->null(),
            'PRIMARY KEY ([[device_id]], [[control_id]])',
        ], $this->tableOptions());
        $this->createIndex('idx_device_control_control', 'device_control', ['control_id', 'result']);
        $this->addForeignKey('fk_device_control_device_id', 'device_control', 'device_id', 'device', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_device_control_control_id', 'device_control', 'control_id', 'control', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_device_control_checked_by', 'device_control', 'checked_by', 'user', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        foreach (['device_control', 'incident_control', 'control_framework_requirement', 'control_attack_mitigation', 'decision_control',
                     'risk_control', 'framework_requirement', 'control_test', 'control'] as $t) {
            $this->dropTable($t);
        }
    }
}
