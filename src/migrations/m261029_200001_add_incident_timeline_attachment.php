<?php

use app\components\Migration;

/**
 * A timeline entry may point at one of the incident's files ("Firewall logs
 * exported"), and keeps that file's name if the file is later removed.
 */
class m261029_200001_add_incident_timeline_attachment extends Migration
{
    public function safeUp()
    {
        $this->addColumn('incident_timeline', 'attachment_id', $this->integer()->null()->after('body')->comment('Linked file on the same incident'));
        $this->addColumn('incident_timeline', 'attachment_name', $this->string(255)->null()->after('attachment_id')->comment('Linked file name, kept after the file is removed'));
        // Removing the file keeps the entry (and the file's name)
        $this->addForeignKey('fk_incident_timeline_attachment_id', 'incident_timeline', 'attachment_id', 'attachment', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_incident_timeline_attachment_id', 'incident_timeline');
        $this->dropColumn('incident_timeline', 'attachment_name');
        $this->dropColumn('incident_timeline', 'attachment_id');
    }
}
