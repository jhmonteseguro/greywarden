<?php

use app\components\Migration;

/**
 * Remediation actions (ACT-0001...): something someone has to do by a date, raised from any
 * record (record_type + record_id, the AuditLog entity type) or on its own.
 */
class m261109_200001_create_action_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('action', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(20)->notNull()->unique(),
            'title' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'status' => $this->string(20)->notNull()->defaultValue('open'),
            'priority' => $this->string(16)->notNull()->defaultValue('medium'),
            'owner_id' => $this->integer()->null(),
            'due_date' => $this->date()->null(),
            'record_type' => $this->string(32)->null(),
            'record_id' => $this->integer()->null(),
            'outcome' => $this->text()->null()->comment('What was done and where the evidence is'),
            'completed_at' => $this->dateTime()->null(),
            'completed_by' => $this->integer()->null(),
        ] + $this->auditColumns() + $this->archiveColumns(), $this->tableOptions());
        $this->createIndex('idx_action_record', 'action', ['record_type', 'record_id']);
        $this->createIndex('idx_action_owner', 'action', ['owner_id', 'status', 'due_date']);
        $this->createIndex('idx_action_due', 'action', ['status', 'due_date']);
        $this->addForeignKey('fk_action_owner', 'action', 'owner_id', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_action_completed_by', 'action', 'completed_by', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('action', ['created_by', 'updated_by', 'archived_by']);
    }

    public function safeDown()
    {
        $this->dropTable('action');
    }
}
