<?php

use app\components\Migration;

/**
 * Threat intel: investigated indicators (IP / ASN) with analyst verdicts and
 * notes, the IP / CIDR / ASN blocklist, and locally cached threat feeds.
 *
 * Address ranges are stored as 32-char lowercase hex of the 16-byte address
 * (IPv4 mapped to ::ffff:a.b.c.d), so string order equals numeric order and
 * containment is a plain indexed comparison: range_start <= ip <= range_end.
 */
class m261019_100001_create_intel_tables extends Migration
{
    /** Nullable CHAR(32) hex address column (binary collation: byte order = address order) */
    private function hex(): string
    {
        return $this->db->driverName === 'mysql' ? 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL' : 'CHAR(32) NULL';
    }

    public function safeUp()
    {
        $this->createTable('intel_indicator', [
            'id' => $this->primaryKey(),
            'type' => $this->string(8)->notNull()->comment('ip | asn'),
            'value' => $this->string(64)->notNull()->comment('Normalised: 192.0.2.1, 2001:db8::1, AS64496'),
            'verdict' => $this->string(16)->null()->comment('Analyst verdict: malicious | suspicious | benign'),
            'verdict_at' => $this->dateTime()->null(),
            'verdict_by' => $this->integer()->null(),
            'last_score' => $this->smallInteger()->null()->comment('Automated score 0-100 from the last lookup'),
            'last_rating' => $this->string(16)->null(),
            'summary' => $this->string(255)->null()->comment('Owner / network line from the last lookup'),
            'country' => $this->char(2)->null(),
            'asn' => $this->bigInteger()->unsigned()->null(),
            'lookup_count' => $this->integer()->notNull()->defaultValue(0),
            'last_checked_at' => $this->dateTime()->null(),
            'last_checked_by' => $this->integer()->null(),
            ...$this->auditColumns(),
        ], $this->tableOptions());
        $this->createIndex('uq_intel_indicator', 'intel_indicator', ['type', 'value'], true);
        $this->createIndex('idx_intel_indicator_checked', 'intel_indicator', 'last_checked_at');
        $this->addUserForeignKeys('intel_indicator', ['created_by', 'updated_by', 'verdict_by', 'last_checked_by']);

        $this->createTable('intel_note', [
            'id' => $this->primaryKey(),
            'indicator_id' => $this->integer()->notNull(),
            'body' => $this->text()->notNull(),
            ...$this->auditColumns(),
        ], $this->tableOptions());
        $this->createIndex('idx_intel_note_indicator', 'intel_note', 'indicator_id');
        $this->addForeignKey('fk_intel_note_indicator', 'intel_note', 'indicator_id', 'intel_indicator', 'id', 'CASCADE', 'CASCADE');
        $this->addUserForeignKeys('intel_note');

        $this->createTable('intel_blocklist', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(32)->notNull()->unique(),
            'indicator_type' => $this->string(8)->notNull()->comment('ip | cidr | asn'),
            'value' => $this->string(64)->notNull(),
            'range_start' => $this->hex(),
            'range_end' => $this->hex(),
            'asn' => $this->bigInteger()->unsigned()->null(),
            'severity' => $this->string(16)->notNull()->defaultValue('high'),
            'direction' => $this->string(16)->notNull()->defaultValue('both'),
            'reason' => $this->text()->notNull(),
            'incident_id' => $this->integer()->null(),
            'expires_at' => $this->dateTime()->null(),
            ...$this->auditColumns(),
            ...$this->archiveColumns(),
        ], $this->tableOptions());
        $this->createIndex('idx_intel_blocklist_type', 'intel_blocklist', ['indicator_type', 'archived_at']);
        $this->createIndex('idx_intel_blocklist_value', 'intel_blocklist', 'value');
        $this->createIndex('idx_intel_blocklist_range', 'intel_blocklist', ['range_start', 'range_end']);
        $this->createIndex('idx_intel_blocklist_asn', 'intel_blocklist', 'asn');
        $this->addForeignKey('fk_intel_blocklist_incident', 'intel_blocklist', 'incident_id', 'incident', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('intel_blocklist', ['created_by', 'updated_by', 'archived_by']);

        $this->createTable('intel_feed', [
            'id' => $this->primaryKey(),
            'feed_key' => $this->string(32)->notNull()->unique(),
            'entry_count' => $this->integer()->notNull()->defaultValue(0),
            'fetched_at' => $this->dateTime()->null()->comment('Last successful download'),
            'attempted_at' => $this->dateTime()->null(),
            'duration_ms' => $this->integer()->null(),
            'last_error' => $this->string(255)->null(),
        ], $this->tableOptions());

        $this->createTable('intel_feed_entry', [
            'id' => $this->bigPrimaryKey(),
            'feed_id' => $this->integer()->notNull(),
            'range_start' => $this->hex(),
            'range_end' => $this->hex(),
            'asn' => $this->bigInteger()->unsigned()->null(),
            'meta' => $this->string(255)->null(),
        ], $this->tableOptions());
        $this->createIndex('idx_intel_feed_entry_range', 'intel_feed_entry', ['feed_id', 'range_start']);
        $this->createIndex('idx_intel_feed_entry_asn', 'intel_feed_entry', ['asn', 'feed_id']);
        $this->addForeignKey('fk_intel_feed_entry_feed', 'intel_feed_entry', 'feed_id', 'intel_feed', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('intel_feed_entry');
        $this->dropTable('intel_feed');
        $this->dropTable('intel_blocklist');
        $this->dropTable('intel_note');
        $this->dropTable('intel_indicator');
    }
}
