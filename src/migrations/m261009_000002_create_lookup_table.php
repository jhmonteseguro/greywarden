<?php

use app\components\Migration;

class m261009_000002_create_lookup_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('lookup', [
            'id' => $this->primaryKey(),
            'type' => $this->string(32)->notNull(),
            'name' => $this->string(128)->notNull(),
            'description' => $this->string(255)->null(),
            'sort_order' => $this->integer()->notNull()->defaultValue(0),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
        ] + $this->auditColumns(), $this->tableOptions());
        $this->createIndex('ux_lookup_type_name', 'lookup', ['type', 'name'], true);
        $this->createIndex('idx_lookup_type_active', 'lookup', ['type', 'is_active', 'sort_order']);
        $this->addUserForeignKeys('lookup');
    }

    public function safeDown()
    {
        $this->dropTable('lookup');
    }
}
