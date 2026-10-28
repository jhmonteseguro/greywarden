<?php

use app\components\Migration;

/**
 * Cloud Inventory: cloud accounts (AWS accounts, Azure subscriptions, GCP projects,
 * SaaS tenants), the cloud assets in them, cloud-check results per asset and per
 * account, and links from risks, incidents and decisions to cloud assets.
 */
class m261028_100001_create_cloud_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('cloud_account', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(20)->notNull()->unique(),
            'name' => $this->string(128)->notNull(),
            'provider' => $this->string(32)->notNull(),
            'account_identifier' => $this->string(128)->null()->comment('AWS account id, subscription id, project id, tenant domain...'),
            'environment' => $this->string(20)->notNull()->defaultValue('production'),
            'owner_id' => $this->integer()->null(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'criticality' => $this->string(16)->notNull()->defaultValue('medium'),
            'data_classification' => $this->string(16)->notNull()->defaultValue('internal'),
            'notes' => $this->text()->null(),
        ] + $this->archiveColumns() + $this->auditColumns(), $this->tableOptions());
        $this->createIndex('idx_cloud_account_identifier', 'cloud_account', ['provider', 'account_identifier'], true);
        $this->createIndex('idx_cloud_account_archived', 'cloud_account', 'archived_at');
        $this->addForeignKey('fk_cloud_account_owner_id', 'cloud_account', 'owner_id', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('cloud_account', ['created_by', 'updated_by', 'archived_by']);

        $this->createTable('cloud_asset', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(20)->notNull()->unique(),
            'name' => $this->string(128)->notNull(),
            'account_id' => $this->integer()->notNull(),
            'resource_type' => $this->string(32)->notNull(),
            'resource_id' => $this->string(512)->null()->comment('ARN / resource id, unique per account'),
            'region' => $this->string(64)->null(),
            'environment' => $this->string(20)->notNull()->defaultValue('production'),
            'owner_id' => $this->integer()->null(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'criticality' => $this->string(16)->notNull()->defaultValue('medium'),
            'data_classification' => $this->string(16)->notNull()->defaultValue('internal'),
            'internet_facing' => $this->boolean()->notNull()->defaultValue(false),
            'public_ips' => $this->string(512)->null()->comment('Comma separated'),
            'public_endpoints' => $this->string(1024)->null()->comment('Comma separated hostnames / URLs'),
            'encryption_at_rest' => $this->string(10)->notNull()->defaultValue('unknown'),
            'os' => $this->string(100)->null(),
            'os_version' => $this->string(64)->null(),
            'source' => $this->string(32)->null(),
            'source_id' => $this->string(128)->null(),
            'first_seen_at' => $this->dateTime()->null(),
            'last_seen_at' => $this->dateTime()->null(),
            'notes' => $this->text()->null(),
        ] + $this->archiveColumns() + $this->auditColumns(), $this->tableOptions());
        $this->createIndex('idx_cloud_asset_resource', 'cloud_asset', ['account_id', 'resource_id'], true);
        $this->createIndex('idx_cloud_asset_source', 'cloud_asset', ['source', 'source_id'], true);
        $this->createIndex('idx_cloud_asset_type', 'cloud_asset', 'resource_type');
        $this->createIndex('idx_cloud_asset_status', 'cloud_asset', 'status');
        $this->createIndex('idx_cloud_asset_last_seen', 'cloud_asset', 'last_seen_at');
        $this->createIndex('idx_cloud_asset_archived', 'cloud_asset', 'archived_at');
        // An account with assets cannot be deleted: archive it, or move / delete its assets first
        $this->addForeignKey('fk_cloud_asset_account_id', 'cloud_asset', 'account_id', 'cloud_account', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_cloud_asset_owner_id', 'cloud_asset', 'owner_id', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('cloud_asset', ['created_by', 'updated_by', 'archived_by']);

        foreach (['cloud_account' => 'cloud_account_id', 'cloud_asset' => 'cloud_asset_id'] as $table => $column) {
            $results = "{$table}_control";
            $this->createTable($results, [
                $column => $this->integer()->notNull(),
                'control_id' => $this->integer()->notNull(),
                'result' => $this->string(10)->notNull()->comment('pass | fail'),
                'checked_on' => $this->date()->null(),
                'checked_by' => $this->integer()->null(),
                "PRIMARY KEY ([[$column]], [[control_id]])",
            ], $this->tableOptions());
            $this->createIndex("idx_{$results}_control", $results, ['control_id', 'result']);
            $this->addForeignKey("fk_{$results}_{$column}", $results, $column, $table, 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey("fk_{$results}_control_id", $results, 'control_id', 'control', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey("fk_{$results}_checked_by", $results, 'checked_by', 'user', 'id', 'SET NULL', 'CASCADE');
        }

        $this->createLinkTable('risk_cloud_asset', 'risk_id', 'risk', 'cloud_asset_id', 'cloud_asset');
        $this->createLinkTable('incident_cloud_asset', 'incident_id', 'incident', 'cloud_asset_id', 'cloud_asset');
        $this->createLinkTable('decision_cloud_asset', 'decision_id', 'decision', 'cloud_asset_id', 'cloud_asset');

        // Cloud checks: a control can also be checked on cloud assets (by resource type) and / or cloud accounts
        $this->addColumn('control', 'cloud_check', $this->string(20)->null()->after('check_max_age_days'));
        $this->addColumn('control', 'cloud_scope', $this->string(1024)->null()->after('cloud_check')
            ->comment('Comma separated resource types and/or "account"; NULL = every asset type'));
        $this->createIndex('idx_control_cloud_check', 'control', 'cloud_check');
    }

    public function safeDown()
    {
        $this->dropIndex('idx_control_cloud_check', 'control');
        $this->dropColumn('control', 'cloud_scope');
        $this->dropColumn('control', 'cloud_check');
        foreach (['decision_cloud_asset', 'incident_cloud_asset', 'risk_cloud_asset', 'cloud_asset_control', 'cloud_account_control', 'cloud_asset', 'cloud_account'] as $t) {
            $this->dropTable($t);
        }
    }
}
