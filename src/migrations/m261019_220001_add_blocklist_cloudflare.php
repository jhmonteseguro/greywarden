<?php

use app\components\Migration;

/**
 * When a blocklist entry was added to the Cloudflare edge rule (NULL = not in
 * Cloudflare), so unblocking it knows to take it out again.
 */
class m261019_220001_add_blocklist_cloudflare extends Migration
{
    public function safeUp()
    {
        $this->addColumn('intel_blocklist', 'cloudflare_added_at', $this->dateTime()->null()->after('expires_at')->comment('Added to the Cloudflare WAF rule'));
    }

    public function safeDown()
    {
        $this->dropColumn('intel_blocklist', 'cloudflare_added_at');
    }
}
