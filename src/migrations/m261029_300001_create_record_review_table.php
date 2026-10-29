<?php

use app\components\Migration;

/**
 * Completed periodic reviews of a risk or decision: when it was reviewed, by
 * whom, what was concluded, the review date it answered and the next review
 * date it set. Recording a review moves the record's review date on.
 */
class m261029_300001_create_record_review_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('record_review', [
            'id' => $this->primaryKey(),
            'entity_type' => $this->string(32)->notNull()->comment('risk | decision'),
            'entity_id' => $this->integer()->notNull(),
            'reviewed_on' => $this->date()->notNull(),
            'reviewed_by_id' => $this->integer()->null(),
            'outcome' => $this->text()->notNull(),
            'due_on' => $this->date()->null()->comment('Review date the record had when this review was recorded'),
            'next_review_date' => $this->date()->null()->comment('Review date this review set'),
            'created_at' => $this->dateTime()->notNull(),
            'created_by' => $this->integer()->null(),
        ], $this->tableOptions());
        $this->createIndex('idx_record_review_entity', 'record_review', ['entity_type', 'entity_id']);
        $this->addForeignKey('fk_record_review_reviewed_by_id', 'record_review', 'reviewed_by_id', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('record_review', ['created_by']);
    }

    public function safeDown()
    {
        $this->dropTable('record_review');
    }
}
