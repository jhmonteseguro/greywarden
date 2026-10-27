<?php

use app\components\Migration;

class m261009_000003_create_audit_log_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('audit_log', [
            'id' => $this->bigPrimaryKey(),
            'entity_type' => $this->string(32)->notNull(),
            'entity_id' => $this->integer()->null(),
            'entity_label' => $this->string(255)->null(),
            'action' => $this->string(32)->notNull(),
            'changes' => $this->text()->null()->comment('JSON: {"field": [old, new]}'),
            'user_id' => $this->integer()->null(),
            'username' => $this->string(64)->null(),
            'ip' => $this->string(45)->null(),
            'user_agent' => $this->string(255)->null(),
            'created_at' => $this->dateTime()->notNull(),
        ], $this->tableOptions());
        $this->createIndex('idx_audit_entity', 'audit_log', ['entity_type', 'entity_id']);
        $this->createIndex('idx_audit_created', 'audit_log', 'created_at');
        $this->createIndex('idx_audit_user', 'audit_log', 'user_id');
        $this->createIndex('idx_audit_action', 'audit_log', 'action');
        $this->addForeignKey('fk_audit_log_user', 'audit_log', 'user_id', 'user', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('audit_log');
    }
}
