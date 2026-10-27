<?php

use app\components\Migration;

/**
 * Fields from endpoint-management exports (Google Workspace devices): the
 * user's email, the source system and its device ID (so a re-import updates
 * the same record instead of duplicating it) and first/last sync times, which
 * flag devices that have stopped checking in.
 */
class m261019_210001_add_device_sync_fields extends Migration
{
    public function safeUp()
    {
        $this->addColumn('device', 'assigned_email', $this->string(254)->null()->after('assigned_to'));
        $this->addColumn('device', 'source', $this->string(32)->null()->after('notes')->comment('Import source, e.g. google'));
        $this->addColumn('device', 'source_id', $this->string(128)->null()->after('source')->comment('Device ID in the source system'));
        $this->addColumn('device', 'first_seen_at', $this->dateTime()->null()->after('source_id'));
        $this->addColumn('device', 'last_seen_at', $this->dateTime()->null()->after('first_seen_at'));
        $this->createIndex('idx_device_assigned_email', 'device', 'assigned_email');
        $this->createIndex('uq_device_source', 'device', ['source', 'source_id'], true);
        $this->createIndex('idx_device_last_seen', 'device', 'last_seen_at');
    }

    public function safeDown()
    {
        $this->dropIndex('idx_device_last_seen', 'device');
        $this->dropIndex('uq_device_source', 'device');
        $this->dropIndex('idx_device_assigned_email', 'device');
        foreach (['last_seen_at', 'first_seen_at', 'source_id', 'source', 'assigned_email'] as $column) {
            $this->dropColumn('device', $column);
        }
    }
}
