<?php

use app\components\Migration;

/**
 * Cloud configuration: the settings an inventory export carries for each asset
 * (public access, encryption, open ports, MFA...) and for each account (root
 * MFA, audit logging...), with the findings Greywarden derives from them, and
 * the provider's own resource type ("s3:bucket") next to the catalogue type.
 */
class m261030_100001_add_cloud_configuration extends Migration
{
    public function safeUp()
    {
        $this->addColumn('cloud_asset', 'provider_type', $this->string(128)->null()->after('resource_type')
            ->comment("The provider's resource type, e.g. s3:bucket"));
        foreach (['cloud_asset', 'cloud_account'] as $table) {
            $this->addColumn($table, 'properties', 'MEDIUMTEXT NULL COMMENT ' . $this->db->quoteValue('Imported configuration (JSON)'));
            $this->addColumn($table, 'findings', $this->text()->null()->comment('Findings derived from the configuration (JSON)'));
            $this->addColumn($table, 'finding_count', $this->smallInteger()->notNull()->defaultValue(0));
            $this->addColumn($table, 'finding_severity', $this->string(16)->null()->comment('Worst finding: low | medium | high | critical'));
        }
        $this->addColumn('cloud_account', 'properties_at', $this->dateTime()->null()->comment('When the configuration was last imported'));
        $this->createIndex('idx_cloud_asset_findings', 'cloud_asset', ['finding_severity', 'finding_count']);
    }

    public function safeDown()
    {
        $this->dropIndex('idx_cloud_asset_findings', 'cloud_asset');
        $this->dropColumn('cloud_account', 'properties_at');
        foreach (['cloud_asset', 'cloud_account'] as $table) {
            foreach (['finding_severity', 'finding_count', 'findings', 'properties'] as $column) {
                $this->dropColumn($table, $column);
            }
        }
        $this->dropColumn('cloud_asset', 'provider_type');
    }
}
