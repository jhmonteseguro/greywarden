<?php

use app\components\Migration;

class m261009_000001_create_user_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('user', [
            'id' => $this->primaryKey(),
            'username' => $this->string(64)->notNull()->unique(),
            'email' => $this->string(190)->notNull()->unique(),
            'full_name' => $this->string(128)->notNull(),
            'job_title' => $this->string(128)->null(),
            'password_hash' => $this->string(255)->notNull(),
            'auth_key' => $this->string(64)->notNull(),
            'status' => $this->smallInteger()->notNull()->defaultValue(10),
            'must_change_password' => $this->boolean()->notNull()->defaultValue(false),
            'password_changed_at' => $this->dateTime()->null(),
            'last_login_at' => $this->dateTime()->null(),
            'last_login_ip' => $this->string(45)->null(),
        ] + $this->auditColumns(), $this->tableOptions());
        $this->createIndex('idx_user_status', 'user', 'status');
        $this->addUserForeignKeys('user');

        $this->createTable('login_attempt', [
            'id' => $this->primaryKey(),
            'username' => $this->string(190)->notNull(),
            'ip' => $this->string(45)->notNull(),
            'success' => $this->boolean()->notNull()->defaultValue(false),
            'user_agent' => $this->string(255)->null(),
            'attempted_at' => $this->dateTime()->notNull(),
        ], $this->tableOptions());
        $this->createIndex('idx_login_attempt_username', 'login_attempt', ['username', 'attempted_at']);
        $this->createIndex('idx_login_attempt_ip', 'login_attempt', ['ip', 'attempted_at']);
    }

    public function safeDown()
    {
        $this->dropTable('login_attempt');
        $this->dropTable('user');
    }
}
