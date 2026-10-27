<?php

use app\components\Migration;

/**
 * MITRE ATT&CK catalogue (tactics, techniques, mitigations) and the links from
 * incidents / risks (techniques) and decisions (mitigations).
 *
 * Catalogue rows are never deleted by the importer: techniques that disappear
 * from a release are flagged deprecated so existing links keep resolving.
 */
class m261019_000001_create_attack_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('attack_release', [
            'id' => $this->primaryKey(),
            'domain' => $this->string(32)->notNull(),
            'version' => $this->string(16)->notNull(),
            'released_at' => $this->date()->null(),
            'source' => $this->string(255)->null(),
            'tactic_count' => $this->integer()->notNull()->defaultValue(0),
            'technique_count' => $this->integer()->notNull()->defaultValue(0),
            'mitigation_count' => $this->integer()->notNull()->defaultValue(0),
            'imported_at' => $this->dateTime()->notNull(),
        ], $this->tableOptions());

        $this->createTable('attack_tactic', [
            'id' => $this->primaryKey(),
            'external_id' => $this->string(16)->notNull()->unique(),
            'shortname' => $this->string(64)->notNull()->comment('Kill-chain phase name, e.g. initial-access'),
            'name' => $this->string(128)->notNull(),
            'description' => $this->text()->null(),
            'sort_order' => $this->integer()->notNull()->defaultValue(0),
            'is_deprecated' => $this->boolean()->notNull()->defaultValue(false),
        ], $this->tableOptions());

        $this->createIndex('idx_attack_tactic_shortname', 'attack_tactic', 'shortname');

        $this->createTable('attack_technique', [
            'id' => $this->primaryKey(),
            'external_id' => $this->string(16)->notNull()->unique(),
            'parent_id' => $this->integer()->null(),
            'name' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'platforms' => $this->string(255)->null()->comment('Comma separated ATT&CK platforms'),
            'is_subtechnique' => $this->boolean()->notNull()->defaultValue(false),
            'is_deprecated' => $this->boolean()->notNull()->defaultValue(false),
            'revoked_by' => $this->string(16)->null()->comment('Replacement technique id'),
            'modified_on' => $this->date()->null(),
        ], $this->tableOptions());
        $this->createIndex('idx_attack_technique_parent', 'attack_technique', 'parent_id');
        $this->createIndex('idx_attack_technique_deprecated', 'attack_technique', ['is_deprecated', 'is_subtechnique']);
        $this->addForeignKey('fk_attack_technique_parent', 'attack_technique', 'parent_id', 'attack_technique', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('attack_mitigation', [
            'id' => $this->primaryKey(),
            'external_id' => $this->string(16)->notNull()->unique(),
            'name' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'is_deprecated' => $this->boolean()->notNull()->defaultValue(false),
        ], $this->tableOptions());

        $this->createTable('attack_technique_tactic', [
            'technique_id' => $this->integer()->notNull(),
            'tactic_id' => $this->integer()->notNull(),
            'PRIMARY KEY ([[technique_id]], [[tactic_id]])',
        ], $this->tableOptions());
        $this->createIndex('idx_attack_technique_tactic_tactic', 'attack_technique_tactic', 'tactic_id');
        $this->addForeignKey('fk_attack_technique_tactic_technique', 'attack_technique_tactic', 'technique_id', 'attack_technique', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_attack_technique_tactic_tactic', 'attack_technique_tactic', 'tactic_id', 'attack_tactic', 'id', 'CASCADE', 'CASCADE');

        $this->createTable('attack_mitigation_technique', [
            'mitigation_id' => $this->integer()->notNull(),
            'technique_id' => $this->integer()->notNull(),
            'PRIMARY KEY ([[mitigation_id]], [[technique_id]])',
        ], $this->tableOptions());
        $this->createIndex('idx_attack_mitigation_technique_technique', 'attack_mitigation_technique', 'technique_id');
        $this->addForeignKey('fk_attack_mitigation_technique_mitigation', 'attack_mitigation_technique', 'mitigation_id', 'attack_mitigation', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_attack_mitigation_technique_technique', 'attack_mitigation_technique', 'technique_id', 'attack_technique', 'id', 'CASCADE', 'CASCADE');

        // Domain links
        $this->createLinkTable('incident_attack_technique', 'incident_id', 'incident', 'technique_id', 'attack_technique');
        $this->createLinkTable('risk_attack_technique', 'risk_id', 'risk', 'technique_id', 'attack_technique');
        $this->createLinkTable('decision_attack_mitigation', 'decision_id', 'decision', 'mitigation_id', 'attack_mitigation');
    }

    public function safeDown()
    {
        foreach (['decision_attack_mitigation', 'risk_attack_technique', 'incident_attack_technique', 'attack_mitigation_technique',
                     'attack_technique_tactic', 'attack_mitigation', 'attack_technique', 'attack_tactic', 'attack_release'] as $t) {
            $this->dropTable($t);
        }
    }
}
