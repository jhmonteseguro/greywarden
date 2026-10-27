<?php

use app\components\Migration;

class m261009_000005_create_decision_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('decision', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(20)->notNull()->unique(),
            'title' => $this->string(255)->notNull(),
            'decision_date' => $this->date()->notNull(),
            'context' => $this->text()->null()->comment('Context / problem statement'),
            'options_considered' => $this->text()->null(),
            'decision_text' => $this->text()->null()->comment('The decision'),
            'rationale' => $this->text()->null(),
            'decision_makers' => $this->string(255)->null(),
            'stakeholders' => $this->text()->null(),
            'category_id' => $this->integer()->null(),
            'status' => $this->string(20)->notNull()->defaultValue('proposed'),
            'superseded_by_id' => $this->integer()->null(),
            'impact' => $this->text()->null()->comment('Impact / consequences'),
            'review_date' => $this->date()->null(),
        ] + $this->archiveColumns() + $this->auditColumns(), $this->tableOptions());

        $this->createIndex('idx_decision_status', 'decision', 'status');
        $this->createIndex('idx_decision_date', 'decision', 'decision_date');
        $this->createIndex('idx_decision_review', 'decision', 'review_date');
        $this->createIndex('idx_decision_archived', 'decision', 'archived_at');
        $this->addForeignKey('fk_decision_category', 'decision', 'category_id', 'lookup', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_decision_superseded_by', 'decision', 'superseded_by_id', 'decision', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('decision', ['created_by', 'updated_by', 'archived_by']);
    }

    public function safeDown()
    {
        $this->dropTable('decision');
    }
}
