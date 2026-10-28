<?php

use app\components\Migration;

/**
 * Imported cloud assets keep the provider's full resource identifier as their
 * source ID: ARNs, Azure resource IDs and Google Cloud full resource names are
 * often longer than 128 characters.
 */
class m261028_100002_widen_cloud_asset_source_id extends Migration
{
    public function safeUp()
    {
        $this->alterColumn('cloud_asset', 'source_id', $this->string(512)->null());
    }

    public function safeDown()
    {
        $this->alterColumn('cloud_asset', 'source_id', $this->string(128)->null());
    }
}
