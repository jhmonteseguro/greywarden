<?php

use app\components\Migration;

/**
 * Edge findings: client IPs whose traffic through Cloudflare looked like
 * probing or scanning (one row per IP, reopened when it comes back), and a log
 * of each pull of the Cloudflare analytics.
 */
class m261029_000001_create_edge_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('edge_finding', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(32)->notNull()->unique(),
            'ip' => $this->string(45)->notNull()->comment('Normalised client address'),
            'asn' => $this->bigInteger()->unsigned()->null(),
            'country' => $this->string(64)->null(),
            'status' => $this->string(16)->notNull()->defaultValue('new')->comment('new | blocked | escalated | dismissed | ignored'),
            'score' => $this->smallInteger()->notNull()->defaultValue(0)->comment('Edge behaviour score 0-100'),
            'priority' => $this->string(16)->notNull()->defaultValue('low')->comment('high | medium | low'),
            'headline' => $this->string(255)->notNull()->defaultValue(''),
            'exposure' => $this->boolean()->notNull()->defaultValue(false)->comment('A sensitive path answered 2xx'),
            'signals' => $this->text()->null()->comment('JSON: why it was flagged'),
            'evidence' => 'MEDIUMTEXT NULL COMMENT ' . $this->db->quoteValue('JSON: accumulated traffic profile'),
            'requests' => $this->integer()->notNull()->defaultValue(0),
            'stopped' => $this->integer()->notNull()->defaultValue(0)->comment('Requests Cloudflare blocked or challenged'),
            'error_paths' => $this->integer()->notNull()->defaultValue(0),
            'times_seen' => $this->integer()->notNull()->defaultValue(1)->comment('Pulls it appeared in'),
            'first_seen_at' => $this->dateTime()->notNull(),
            'last_seen_at' => $this->dateTime()->notNull(),
            'intel_score' => $this->smallInteger()->null(),
            'intel_rating' => $this->string(16)->null(),
            'intel_checked_at' => $this->dateTime()->null(),
            'blocklist_id' => $this->integer()->null(),
            'incident_id' => $this->integer()->null(),
            'note' => $this->string(500)->null(),
            'resolved_at' => $this->dateTime()->null(),
            'resolved_by' => $this->integer()->null(),
            ...$this->auditColumns(),
            ...$this->archiveColumns(),
        ], $this->tableOptions());
        $this->createIndex('uq_edge_finding_ip', 'edge_finding', 'ip', true);
        $this->createIndex('idx_edge_finding_status', 'edge_finding', ['status', 'priority']);
        $this->createIndex('idx_edge_finding_asn', 'edge_finding', 'asn');
        $this->createIndex('idx_edge_finding_seen', 'edge_finding', 'last_seen_at');
        $this->addForeignKey('fk_edge_finding_blocklist', 'edge_finding', 'blocklist_id', 'intel_blocklist', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_edge_finding_incident', 'edge_finding', 'incident_id', 'incident', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('edge_finding', ['created_by', 'updated_by', 'archived_by', 'resolved_by']);

        $this->createTable('edge_pull', [
            'id' => $this->primaryKey(),
            'started_at' => $this->dateTime()->notNull(),
            'finished_at' => $this->dateTime()->null(),
            'window_start' => $this->dateTime()->null(),
            'window_end' => $this->dateTime()->null(),
            'status' => $this->string(16)->notNull()->comment('ok | partial | error'),
            'message' => $this->string(500)->null(),
            'requests' => $this->integer()->notNull()->defaultValue(0),
            'ips' => $this->integer()->notNull()->defaultValue(0),
            'findings_new' => $this->integer()->notNull()->defaultValue(0),
            'findings_updated' => $this->integer()->notNull()->defaultValue(0),
            'run_by' => $this->integer()->null(),
        ], $this->tableOptions());
        $this->createIndex('idx_edge_pull_started', 'edge_pull', 'started_at');
        $this->addForeignKey('fk_edge_pull_run_by', 'edge_pull', 'run_by', 'user', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('edge_pull');
        $this->dropTable('edge_finding');
    }
}
