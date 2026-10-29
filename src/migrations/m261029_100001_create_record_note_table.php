<?php

use app\components\Migration;

/**
 * Short dated notes on a record ("emailed DevOps requesting xyz"), shown on the
 * record and in its PDF. Notes are never edited; a note may point at one of the
 * record's files, and keeps that file's name if the file is later removed.
 */
class m261029_100001_create_record_note_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('record_note', [
            'id' => $this->primaryKey(),
            'entity_type' => $this->string(32)->notNull()->comment('control'),
            'entity_id' => $this->integer()->notNull(),
            'body' => $this->text()->notNull(),
            'attachment_id' => $this->integer()->null()->comment('Linked file on the same record'),
            'attachment_name' => $this->string(255)->null()->comment('Linked file name, kept after the file is removed'),
            'created_at' => $this->dateTime()->notNull(),
            'created_by' => $this->integer()->null(),
        ], $this->tableOptions());
        $this->createIndex('idx_record_note_entity', 'record_note', ['entity_type', 'entity_id']);
        // Removing the file keeps the note (and the file's name)
        $this->addForeignKey('fk_record_note_attachment_id', 'record_note', 'attachment_id', 'attachment', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('record_note', ['created_by']);
    }

    public function safeDown()
    {
        $this->dropTable('record_note');
    }
}
