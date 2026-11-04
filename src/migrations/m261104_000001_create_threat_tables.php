<?php

use app\components\Actor;
use app\components\Migration;
use app\models\Organisation;
use app\modules\threat\components\ThreatCatalogue;

/**
 * Threat Watch: security news and advisories gathered from public feeds.
 *
 * Shared intelligence, stored once (public facts, the same for everybody):
 *   threat_source, threat_article, threat_story, threat_story_cve, threat_cve, threat_pull
 * Per organisation (what it watches and what it decided):
 *   threat_watch_term, threat_triage
 */
class m261104_000001_create_threat_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('organisation', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'created_at' => $this->dateTime()->notNull(),
            'updated_at' => $this->dateTime()->notNull(),
        ], $this->tableOptions());

        $this->createTable('threat_source', [
            'id' => $this->primaryKey(),
            'key' => $this->string(64)->notNull()->unique()->comment('Catalogue key, or custom-<n>'),
            'name' => $this->string(120)->notNull(),
            'category' => $this->string(16)->notNull()->comment('exploited | advisory | government | research | exploit | news'),
            'url' => $this->string(1000)->notNull(),
            'format' => $this->string(16)->notNull()->defaultValue('rss')->comment('rss (RSS or Atom) | kev'),
            'is_custom' => $this->boolean()->notNull()->defaultValue(false)->comment('Added by an admin, not in the catalogue'),
            'is_quiet' => $this->boolean()->notNull()->defaultValue(false)->comment('Too many items to read: only shown when they match'),
            'is_enabled' => $this->boolean()->notNull()->defaultValue(true),
            'interval_minutes' => $this->integer()->notNull()->defaultValue(60),
            'last_fetched_at' => $this->dateTime()->null(),
            'last_success_at' => $this->dateTime()->null(),
            'last_error' => $this->string(500)->null(),
            'failures' => $this->integer()->notNull()->defaultValue(0)->comment('Failed fetches in a row'),
            'items_total' => $this->integer()->notNull()->defaultValue(0),
            'body_hash' => $this->char(64)->null()->comment('Last body read, to skip unchanged ones'),
            ...$this->auditColumns(),
        ], $this->tableOptions());
        $this->addUserForeignKeys('threat_source');

        $this->createTable('threat_cve', [
            'cve_id' => $this->string(32)->notNull(),
            'description' => $this->text()->null(),
            'vendor' => $this->string(120)->null(),
            'product' => $this->string(200)->null(),
            'kev_added_on' => $this->date()->null()->comment('In the CISA KEV catalogue since'),
            'kev_due_on' => $this->date()->null(),
            'kev_ransomware' => $this->boolean()->notNull()->defaultValue(false),
            'kev_action' => $this->text()->null(),
            'cvss_score' => $this->decimal(3, 1)->null(),
            'cvss_severity' => $this->string(16)->null(),
            'nvd_checked_at' => $this->dateTime()->null(),
            'epss_score' => $this->decimal(6, 5)->null()->comment('Probability of exploitation in the next 30 days'),
            'epss_percentile' => $this->decimal(6, 5)->null(),
            'epss_checked_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull(),
        ], $this->tableOptions());
        $this->addPrimaryKey('pk_threat_cve', 'threat_cve', 'cve_id');
        $this->createIndex('idx_threat_cve_kev', 'threat_cve', 'kev_added_on');

        $this->createTable('threat_story', [
            'id' => $this->primaryKey(),
            'title' => $this->string(500)->notNull(),
            'summary' => $this->text()->null(),
            'is_quiet' => $this->boolean()->notNull()->defaultValue(true)->comment('Only quiet sources so far'),
            'is_roundup' => $this->boolean()->notNull()->defaultValue(false)->comment('Covers many CVEs, so other articles never join it by CVE'),
            'exploited_language' => $this->boolean()->notNull()->defaultValue(false)->comment('An article reports zero-day or in-the-wild exploitation'),
            'article_count' => $this->integer()->notNull()->defaultValue(0),
            'source_count' => $this->integer()->notNull()->defaultValue(0),
            'first_seen_at' => $this->dateTime()->notNull()->comment('Earliest article'),
            'last_seen_at' => $this->dateTime()->notNull()->comment('Latest article'),
        ], $this->tableOptions());
        $this->createIndex('idx_threat_story_seen', 'threat_story', 'last_seen_at');
        $this->createIndex('idx_threat_story_title', 'threat_story', ['is_roundup', 'last_seen_at']);

        $this->createTable('threat_story_cve', [
            'story_id' => $this->integer()->notNull(),
            'cve_id' => $this->string(32)->notNull(),
        ], $this->tableOptions());
        $this->addPrimaryKey('pk_threat_story_cve', 'threat_story_cve', ['story_id', 'cve_id']);
        $this->createIndex('idx_threat_story_cve_cve', 'threat_story_cve', 'cve_id');
        $this->addForeignKey('fk_threat_story_cve_story', 'threat_story_cve', 'story_id', 'threat_story', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_threat_story_cve_cve', 'threat_story_cve', 'cve_id', 'threat_cve', 'cve_id', 'CASCADE', 'CASCADE');

        $this->createTable('threat_article', [
            'id' => $this->primaryKey(),
            'source_id' => $this->integer()->notNull(),
            'story_id' => $this->integer()->notNull(),
            'guid_hash' => $this->char(64)->notNull()->comment('SHA-256 of the item id, to skip items already read'),
            'title' => $this->string(500)->notNull(),
            'url' => $this->string(2000)->notNull(),
            'excerpt' => $this->text()->null()->comment('A short extract only: the article stays with its publisher'),
            'published_at' => $this->dateTime()->notNull(),
            'fetched_at' => $this->dateTime()->notNull(),
        ], $this->tableOptions());
        $this->createIndex('uq_threat_article_guid', 'threat_article', ['source_id', 'guid_hash'], true);
        $this->createIndex('idx_threat_article_story', 'threat_article', ['story_id', 'published_at']);
        $this->addForeignKey('fk_threat_article_source', 'threat_article', 'source_id', 'threat_source', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_threat_article_story', 'threat_article', 'story_id', 'threat_story', 'id', 'CASCADE', 'CASCADE');

        $this->createTable('threat_pull', [
            'id' => $this->primaryKey(),
            'started_at' => $this->dateTime()->notNull(),
            'finished_at' => $this->dateTime()->null(),
            'status' => $this->string(16)->notNull()->comment('ok | partial | error'),
            'message' => $this->string(500)->null(),
            'sources_read' => $this->integer()->notNull()->defaultValue(0),
            'sources_failed' => $this->integer()->notNull()->defaultValue(0),
            'articles_new' => $this->integer()->notNull()->defaultValue(0),
            'stories_new' => $this->integer()->notNull()->defaultValue(0),
            'cves_enriched' => $this->integer()->notNull()->defaultValue(0),
            'run_by' => $this->integer()->null(),
        ], $this->tableOptions());
        $this->createIndex('idx_threat_pull_started', 'threat_pull', 'started_at');
        $this->addForeignKey('fk_threat_pull_run_by', 'threat_pull', 'run_by', 'user', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('threat_watch_term', [
            'id' => $this->primaryKey(),
            'organisation_id' => $this->integer()->notNull(),
            'term' => $this->string(120)->notNull(),
            'category' => $this->string(16)->notNull()->comment('network | identity | email | endpoint | platform | device | supplier | other'),
            'origin' => $this->string(16)->notNull()->comment('starter | manual | device | cloud | supplier'),
            'is_enabled' => $this->boolean()->notNull()->defaultValue(true),
            ...$this->auditColumns(),
        ], $this->tableOptions());
        $this->createIndex('uq_threat_watch_term', 'threat_watch_term', ['organisation_id', 'term'], true);
        $this->addForeignKey('fk_threat_watch_term_org', 'threat_watch_term', 'organisation_id', 'organisation', 'id', 'CASCADE', 'CASCADE');
        $this->addUserForeignKeys('threat_watch_term');

        $this->createTable('threat_triage', [
            'id' => $this->primaryKey(),
            'organisation_id' => $this->integer()->notNull(),
            'story_id' => $this->integer()->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('new')->comment('new | relevant | dismissed'),
            'priority' => $this->string(16)->notNull()->defaultValue('low')->comment('critical | high | medium | low'),
            'score' => $this->smallInteger()->notNull()->defaultValue(0),
            'signals' => $this->text()->null()->comment('JSON: why it ranks where it does'),
            'matched_terms' => $this->text()->null()->comment('JSON: watchlist terms found'),
            'is_matched' => $this->boolean()->notNull()->defaultValue(false)->comment('A watchlist term is in a headline or a CVE product'),
            'is_exploited' => $this->boolean()->notNull()->defaultValue(false)->comment('In KEV, or an article reports exploitation'),
            'is_hidden' => $this->boolean()->notNull()->defaultValue(false)->comment('Background advisory: quiet sources only and nothing makes it matter'),
            'note' => $this->string(500)->null(),
            'resolved_at' => $this->dateTime()->null(),
            'resolved_by' => $this->integer()->null(),
            ...$this->auditColumns(),
        ], $this->tableOptions());
        $this->createIndex('uq_threat_triage', 'threat_triage', ['organisation_id', 'story_id'], true);
        $this->createIndex('idx_threat_triage_queue', 'threat_triage', ['organisation_id', 'status', 'is_hidden', 'priority']);
        $this->addForeignKey('fk_threat_triage_org', 'threat_triage', 'organisation_id', 'organisation', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_threat_triage_story', 'threat_triage', 'story_id', 'threat_story', 'id', 'CASCADE', 'CASCADE');
        $this->addUserForeignKeys('threat_triage', ['created_by', 'updated_by', 'resolved_by']);

        $now = Actor::now();
        $this->insert('organisation', ['id' => Organisation::DEFAULT_ID, 'name' => 'Default organisation', 'created_at' => $now, 'updated_at' => $now]);
        $rows = [];
        foreach (require Yii::getAlias('@app/data/threat/starter-watchlist.php') as $category => $terms) {
            foreach ($terms as $term) {
                $rows[] = [Organisation::DEFAULT_ID, $term, $category, 'starter', 1, $now, $now];
            }
        }
        $this->batchInsert('threat_watch_term', ['organisation_id', 'term', 'category', 'origin', 'is_enabled', 'created_at', 'updated_at'], $rows);
        ThreatCatalogue::sync();
    }

    public function safeDown()
    {
        foreach (['threat_triage', 'threat_watch_term', 'threat_pull', 'threat_article', 'threat_story_cve', 'threat_story', 'threat_cve', 'threat_source', 'organisation'] as $table) {
            $this->dropTable($table);
        }
    }
}
