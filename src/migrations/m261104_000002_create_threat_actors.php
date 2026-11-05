<?php

use app\components\Migration;
use app\modules\attack\components\CatalogueImporter;

/**
 * Threat actors, and raising records from a story.
 *
 * Shared facts: attack_group and attack_group_technique (the MITRE ATT&CK catalogue),
 * threat_article_actor (which articles name which group).
 * Per organisation: threat_triage_risk and threat_triage_incident hang off its own triage.
 */
class m261104_000002_create_threat_actors extends Migration
{
    public function safeUp()
    {
        $this->createTable('attack_group', [
            'id' => $this->primaryKey(),
            'external_id' => $this->string(16)->notNull()->unique(),
            'name' => $this->string(255)->notNull(),
            'aliases' => $this->text()->null()->comment('JSON list of other names, without the group\'s own'),
            'description' => $this->text()->null(),
            'is_deprecated' => $this->boolean()->notNull()->defaultValue(false),
            'revoked_by' => $this->string(16)->null()->comment('Replacement group id'),
            'modified_on' => $this->date()->null(),
        ], $this->tableOptions());
        $this->createIndex('idx_attack_group_deprecated', 'attack_group', ['is_deprecated', 'name']);

        $this->createTable('attack_group_technique', [
            'group_id' => $this->integer()->notNull(),
            'technique_id' => $this->integer()->notNull(),
            'PRIMARY KEY ([[group_id]], [[technique_id]])',
        ], $this->tableOptions());
        $this->createIndex('idx_attack_group_technique_technique', 'attack_group_technique', 'technique_id');
        $this->addForeignKey('fk_attack_group_technique_group', 'attack_group_technique', 'group_id', 'attack_group', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_attack_group_technique_technique', 'attack_group_technique', 'technique_id', 'attack_technique', 'id', 'CASCADE', 'CASCADE');

        $this->addColumn('threat_article', 'actors_checked', $this->boolean()->notNull()->defaultValue(false)->comment('Read for threat group names'));
        $this->createIndex('idx_threat_article_actors_checked', 'threat_article', 'actors_checked');

        $this->createTable('threat_article_actor', [
            'article_id' => $this->integer()->notNull(),
            'group_id' => $this->integer()->notNull(),
            'PRIMARY KEY ([[article_id]], [[group_id]])',
        ], $this->tableOptions());
        $this->createIndex('idx_threat_article_actor_group', 'threat_article_actor', 'group_id');
        $this->addForeignKey('fk_threat_article_actor_article', 'threat_article_actor', 'article_id', 'threat_article', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_threat_article_actor_group', 'threat_article_actor', 'group_id', 'attack_group', 'id', 'CASCADE', 'CASCADE');

        $this->createLinkTable('threat_triage_risk', 'triage_id', 'threat_triage', 'risk_id', 'risk');
        $this->createLinkTable('threat_triage_incident', 'triage_id', 'threat_triage', 'incident_id', 'incident');

        $stats = (new CatalogueImporter($this->db))->importGroups(CatalogueImporter::readSnapshot());
        echo sprintf("    > ATT&CK: %d threat groups, %d techniques they use\n", $stats['groups'], $stats['uses']);
    }

    public function safeDown()
    {
        foreach (['threat_triage_incident', 'threat_triage_risk', 'threat_article_actor', 'attack_group_technique', 'attack_group'] as $table) {
            $this->dropTable($table);
        }
        $this->dropColumn('threat_article', 'actors_checked');
    }
}
