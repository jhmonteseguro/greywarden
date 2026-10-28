<?php

use app\components\Migration;

/**
 * Security properties an incident affected: confidentiality, integrity and
 * availability. Independent flags, so an incident can affect any combination.
 */
class m261028_000004_add_incident_cia_impact extends Migration
{
    public function safeUp()
    {
        $this->addColumn('incident', 'impact_confidentiality', $this->boolean()->notNull()->defaultValue(false)->after('description'));
        $this->addColumn('incident', 'impact_integrity', $this->boolean()->notNull()->defaultValue(false)->after('impact_confidentiality'));
        $this->addColumn('incident', 'impact_availability', $this->boolean()->notNull()->defaultValue(false)->after('impact_integrity'));
    }

    public function safeDown()
    {
        $this->dropColumn('incident', 'impact_availability');
        $this->dropColumn('incident', 'impact_integrity');
        $this->dropColumn('incident', 'impact_confidentiality');
    }
}
