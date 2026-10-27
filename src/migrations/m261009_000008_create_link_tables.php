<?php

use app\components\Migration;

/**
 * Many-to-many links between domain records.
 */
class m261009_000008_create_link_tables extends Migration
{
    public function safeUp()
    {
        $this->createLinkTable('risk_device', 'risk_id', 'risk', 'device_id', 'device');
        $this->createLinkTable('risk_incident', 'risk_id', 'risk', 'incident_id', 'incident');
        $this->createLinkTable('risk_decision', 'risk_id', 'risk', 'decision_id', 'decision');
        $this->createLinkTable('decision_incident', 'decision_id', 'decision', 'incident_id', 'incident');
        $this->createLinkTable('incident_device', 'incident_id', 'incident', 'device_id', 'device');
    }

    public function safeDown()
    {
        foreach (['incident_device', 'decision_incident', 'risk_decision', 'risk_incident', 'risk_device'] as $t) {
            $this->dropTable($t);
        }
    }
}
