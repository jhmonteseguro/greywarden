<?php

use app\components\Migration;

/**
 * Supplier Register: third parties the organisation relies on, their
 * security-relevant contract terms and assurance (certificates, reports),
 * supplier-check results, links to cloud accounts (an account belongs to one
 * supplier) and links from risks, incidents, decisions and controls.
 */
class m261029_500001_create_supplier_tables extends Migration
{
    private const CATEGORIES = [
        'Cloud infrastructure (IaaS / PaaS)', 'SaaS application', 'Managed IT / MSP', 'Software vendor', 'Domains & DNS',
        'Telecoms & connectivity', 'Payments', 'Professional services', 'Facilities & hardware', 'Other',
    ];

    public function safeUp()
    {
        $this->createTable('supplier', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(20)->notNull()->unique(),
            'name' => $this->string(128)->notNull(),
            'legal_name' => $this->string(255)->null(),
            'company_number' => $this->string(64)->null(),
            'website' => $this->string(255)->null(),
            'category_id' => $this->integer()->null(),
            'services' => $this->text()->null()->comment('What they provide'),
            'owner_id' => $this->integer()->null()->comment('Business owner of the relationship'),
            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'criticality' => $this->string(16)->notNull()->defaultValue('medium'),
            'data_classification' => $this->string(16)->notNull()->defaultValue('internal'),
            'personal_data' => $this->boolean()->notNull()->defaultValue(false),
            'access_level' => $this->string(16)->notNull()->defaultValue('none'),
            'tier' => $this->tinyInteger()->notNull()->defaultValue(3)->comment('Derived from criticality, data and access (1 = critical)'),
            'data_locations' => $this->string(255)->null(),
            'contract_start' => $this->date()->null(),
            'contract_end' => $this->date()->null()->comment('Renewal or end date'),
            'notice_days' => $this->integer()->null(),
            'dpa_status' => $this->string(16)->null()->comment('signed | not_signed; NULL = unknown'),
            'breach_notice_hours' => $this->integer()->null(),
            'right_to_audit' => $this->string(8)->null()->comment('yes | no; NULL = unknown'),
            'security_contact' => $this->string(255)->null(),
            'status_page_url' => $this->string(255)->null(),
            'exit_plan' => $this->text()->null(),
            'next_review_date' => $this->date()->null(),
            'notes' => $this->text()->null(),
        ] + $this->archiveColumns() + $this->auditColumns(), $this->tableOptions());
        $this->createIndex('idx_supplier_status', 'supplier', 'status');
        $this->createIndex('idx_supplier_tier', 'supplier', 'tier');
        $this->createIndex('idx_supplier_review', 'supplier', 'next_review_date');
        $this->createIndex('idx_supplier_archived', 'supplier', 'archived_at');
        $this->addForeignKey('fk_supplier_category_id', 'supplier', 'category_id', 'lookup', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_supplier_owner_id', 'supplier', 'owner_id', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('supplier', ['created_by', 'updated_by', 'archived_by']);

        $this->createTable('supplier_assurance', [
            'id' => $this->primaryKey(),
            'supplier_id' => $this->integer()->notNull(),
            'type' => $this->string(32)->notNull(),
            'description' => $this->string(255)->null()->comment('Scope, report period, certificate number...'),
            'issued_on' => $this->date()->null(),
            'expires_on' => $this->date()->null(),
            'evidence' => $this->string(512)->null(),
            'created_at' => $this->dateTime()->notNull(),
            'created_by' => $this->integer()->null(),
        ], $this->tableOptions());
        $this->createIndex('idx_supplier_assurance_supplier', 'supplier_assurance', ['supplier_id', 'type', 'expires_on']);
        $this->addForeignKey('fk_supplier_assurance_supplier_id', 'supplier_assurance', 'supplier_id', 'supplier', 'id', 'CASCADE', 'CASCADE');
        $this->addUserForeignKeys('supplier_assurance', ['created_by']);

        $this->createTable('supplier_control', [
            'supplier_id' => $this->integer()->notNull(),
            'control_id' => $this->integer()->notNull(),
            'result' => $this->string(10)->notNull()->comment('pass | fail'),
            'checked_on' => $this->date()->null(),
            'checked_by' => $this->integer()->null(),
            'PRIMARY KEY ([[supplier_id]], [[control_id]])',
        ], $this->tableOptions());
        $this->createIndex('idx_supplier_control_control', 'supplier_control', ['control_id', 'result']);
        $this->addForeignKey('fk_supplier_control_supplier_id', 'supplier_control', 'supplier_id', 'supplier', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_supplier_control_control_id', 'supplier_control', 'control_id', 'control', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_supplier_control_checked_by', 'supplier_control', 'checked_by', 'user', 'id', 'SET NULL', 'CASCADE');

        $this->createLinkTable('risk_supplier', 'risk_id', 'risk', 'supplier_id', 'supplier');
        $this->createLinkTable('incident_supplier', 'incident_id', 'incident', 'supplier_id', 'supplier');
        $this->createLinkTable('decision_supplier', 'decision_id', 'decision', 'supplier_id', 'supplier');
        $this->createLinkTable('control_supplier', 'control_id', 'control', 'supplier_id', 'supplier');

        // A cloud account is contracted with one supplier
        $this->addColumn('cloud_account', 'supplier_id', $this->integer()->null()->after('provider'));
        $this->addForeignKey('fk_cloud_account_supplier_id', 'cloud_account', 'supplier_id', 'supplier', 'id', 'SET NULL', 'CASCADE');

        // Supplier checks: a control can also be checked on suppliers (of some tiers)
        $this->addColumn('control', 'supplier_check', $this->string(20)->null()->after('cloud_scope'));
        $this->addColumn('control', 'supplier_tiers', $this->string(16)->null()->after('supplier_check')
            ->comment('Comma separated tiers (1,2,3); NULL = every tier'));
        $this->createIndex('idx_control_supplier_check', 'control', 'supplier_check');

        $now = date('Y-m-d H:i:s');
        $this->batchInsert('lookup', ['type', 'name', 'sort_order', 'is_active', 'created_at', 'updated_at'],
            array_map(static fn($name, $i) => ['supplier_category', $name, ($i + 1) * 10, 1, $now, $now], self::CATEGORIES, array_keys(self::CATEGORIES)));
    }

    public function safeDown()
    {
        $this->delete('lookup', ['type' => 'supplier_category']);
        $this->dropIndex('idx_control_supplier_check', 'control');
        $this->dropColumn('control', 'supplier_tiers');
        $this->dropColumn('control', 'supplier_check');
        $this->dropForeignKey('fk_cloud_account_supplier_id', 'cloud_account');
        $this->dropColumn('cloud_account', 'supplier_id');
        foreach (['control_supplier', 'decision_supplier', 'incident_supplier', 'risk_supplier', 'supplier_control', 'supplier_assurance', 'supplier'] as $t) {
            $this->dropTable($t);
        }
    }
}
