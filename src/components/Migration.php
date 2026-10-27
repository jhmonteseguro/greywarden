<?php

namespace app\components;

/**
 * Base migration with helpers for Greywarden's standard columns.
 */
class Migration extends \yii\db\Migration
{
    protected function tableOptions(): ?string
    {
        return $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;
    }

    /** created_at / updated_at / created_by / updated_by */
    protected function auditColumns(): array
    {
        return [
            'created_at' => $this->dateTime()->notNull(),
            'updated_at' => $this->dateTime()->notNull(),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
        ];
    }

    /** Soft delete (archive) columns */
    protected function archiveColumns(): array
    {
        return [
            'archived_at' => $this->dateTime()->null(),
            'archived_by' => $this->integer()->null(),
        ];
    }

    /** FKs from created_by/updated_by(/archived_by) to user.id */
    protected function addUserForeignKeys(string $table, array $columns = ['created_by', 'updated_by']): void
    {
        foreach ($columns as $column) {
            $this->addForeignKey("fk_{$table}_{$column}", $table, $column, 'user', 'id', 'SET NULL', 'CASCADE');
        }
    }

    protected function createLinkTable(string $table, string $left, string $leftTable, string $right, string $rightTable): void
    {
        $this->createTable($table, [
            $left => $this->integer()->notNull(),
            $right => $this->integer()->notNull(),
            'created_at' => $this->dateTime()->notNull(),
            'created_by' => $this->integer()->null(),
            'PRIMARY KEY ([[' . $left . ']], [[' . $right . ']])',
        ], $this->tableOptions());
        $this->createIndex("idx_{$table}_{$right}", $table, $right);
        $this->addForeignKey("fk_{$table}_{$left}", $table, $left, $leftTable, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey("fk_{$table}_{$right}", $table, $right, $rightTable, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey("fk_{$table}_created_by", $table, 'created_by', 'user', 'id', 'SET NULL', 'CASCADE');
    }
}
