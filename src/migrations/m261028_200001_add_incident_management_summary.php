<?php

use app\components\Migration;

/**
 * Plain-English summary of an incident for management and non-technical staff.
 * It opens the incident PDF report; the description stays the technical account.
 */
class m261028_200001_add_incident_management_summary extends Migration
{
    public function safeUp()
    {
        $this->addColumn('incident', 'management_summary', $this->text()->null()->after('description'));
    }

    public function safeDown()
    {
        $this->dropColumn('incident', 'management_summary');
    }
}
