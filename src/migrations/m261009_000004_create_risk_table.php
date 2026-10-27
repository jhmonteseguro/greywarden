<?php

use app\components\Migration;

class m261009_000004_create_risk_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('risk', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(20)->notNull()->unique(),
            'title' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'category_id' => $this->integer()->null(),
            'scope' => $this->string(255)->null()->comment('Asset / scope affected'),
            'threat_source' => $this->text()->null(),
            'vulnerability' => $this->text()->null(),
            'owner_id' => $this->integer()->null(),
            'inherent_likelihood' => $this->tinyInteger()->notNull(),
            'inherent_impact' => $this->tinyInteger()->notNull(),
            'inherent_score' => $this->tinyInteger()->notNull(),
            'existing_controls' => $this->text()->null(),
            'treatment' => $this->string(16)->null(),
            'treatment_plan' => $this->text()->null(),
            'residual_likelihood' => $this->tinyInteger()->null(),
            'residual_impact' => $this->tinyInteger()->null(),
            'residual_score' => $this->tinyInteger()->null(),
            'date_identified' => $this->date()->notNull(),
            'target_treatment_date' => $this->date()->null(),
            'next_review_date' => $this->date()->null(),
            'status' => $this->string(20)->notNull()->defaultValue('identified'),
            'acceptance_justification' => $this->text()->null(),
            'accepted_by_id' => $this->integer()->null(),
            'accepted_at' => $this->date()->null(),
        ] + $this->archiveColumns() + $this->auditColumns(), $this->tableOptions());

        $this->createIndex('idx_risk_status', 'risk', 'status');
        $this->createIndex('idx_risk_owner', 'risk', 'owner_id');
        $this->createIndex('idx_risk_category', 'risk', 'category_id');
        $this->createIndex('idx_risk_inherent', 'risk', ['inherent_likelihood', 'inherent_impact']);
        $this->createIndex('idx_risk_residual', 'risk', ['residual_likelihood', 'residual_impact']);
        $this->createIndex('idx_risk_review', 'risk', 'next_review_date');
        $this->createIndex('idx_risk_target', 'risk', 'target_treatment_date');
        $this->createIndex('idx_risk_archived', 'risk', 'archived_at');

        $this->addForeignKey('fk_risk_category', 'risk', 'category_id', 'lookup', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_risk_owner', 'risk', 'owner_id', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_risk_accepted_by', 'risk', 'accepted_by_id', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('risk', ['created_by', 'updated_by', 'archived_by']);
    }

    public function safeDown()
    {
        $this->dropTable('risk');
    }
}
