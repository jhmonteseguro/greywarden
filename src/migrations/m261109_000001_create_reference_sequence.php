<?php

use app\components\Migration;

/**
 * The last reference number handed out per prefix (RSK, INC, CTL...), so a number is never
 * reused after the newest record is hard-deleted. Starts empty: the first reference after the
 * upgrade continues from the highest one already in each table.
 */
class m261109_000001_create_reference_sequence extends Migration
{
    public function safeUp()
    {
        $this->createTable('reference_sequence', [
            'prefix' => $this->string(16)->notNull(),
            'last_number' => $this->integer()->unsigned()->notNull(),
            'PRIMARY KEY ([[prefix]])',
        ], $this->tableOptions());
    }

    public function safeDown()
    {
        $this->dropTable('reference_sequence');
    }
}
