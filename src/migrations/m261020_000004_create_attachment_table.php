<?php

use app\components\Migration;

/**
 * File evidence attached to risks, controls (and their tests), decisions and
 * incidents. The bytes live outside the web root under params[attachments.path];
 * this table holds the metadata and the random name they are stored under.
 */
class m261020_000004_create_attachment_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('attachment', [
            'id' => $this->primaryKey(),
            'entity_type' => $this->string(32)->notNull()->comment('risk | control | decision | incident'),
            'entity_id' => $this->integer()->notNull(),
            'control_test_id' => $this->integer()->null()->comment('Set when the file is evidence of a control test'),
            'stored_name' => $this->string(64)->notNull()->unique()->comment('Random name on disk'),
            'original_name' => $this->string(255)->notNull(),
            'mime_type' => $this->string(100)->notNull(),
            'size' => $this->integer()->unsigned()->notNull(),
            'sha256' => $this->char(64)->notNull(),
            'description' => $this->string(255)->null(),
            'created_at' => $this->dateTime()->notNull(),
            'created_by' => $this->integer()->null(),
        ], $this->tableOptions());
        $this->createIndex('idx_attachment_entity', 'attachment', ['entity_type', 'entity_id']);
        // Removing a test keeps its files on the control
        $this->addForeignKey('fk_attachment_control_test_id', 'attachment', 'control_test_id', 'control_test', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('attachment', ['created_by']);
    }

    public function safeDown()
    {
        $this->dropTable('attachment');
    }
}
