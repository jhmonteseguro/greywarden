<?php

use app\components\Migration;

/**
 * Management reports: a security posture report for a period (month, quarter,
 * year to date or custom). The figures are worked out when the report is
 * generated and frozen in `data` (JSON), so a report never changes after the
 * fact; analysts write the management summary and overall status, and the
 * report is approved (signed off) once it is ready to go out.
 */
class m261030_000001_create_report_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('report', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(20)->notNull()->unique(),
            'title' => $this->string(255)->notNull(),
            'period_type' => $this->string(16)->notNull()->comment('month | quarter | year | custom'),
            'period_start' => $this->date()->notNull(),
            'period_end' => $this->date()->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('draft')->comment('draft | approved'),
            'rag' => $this->string(8)->null()->comment('green | amber | red: overall status chosen by the author'),
            'management_summary' => $this->text()->null(),
            'data' => 'LONGTEXT NULL',
            'data_version' => $this->smallInteger()->notNull()->defaultValue(1),
            'generated_at' => $this->dateTime()->null(),
            'generated_by' => $this->integer()->null(),
            'approved_at' => $this->dateTime()->null(),
            'approved_by_id' => $this->integer()->null(),
            'approval_note' => $this->text()->null(),
        ] + $this->archiveColumns() + $this->auditColumns(), $this->tableOptions());
        $this->createIndex('idx_report_period', 'report', ['period_end', 'period_start']);
        $this->createIndex('idx_report_status', 'report', 'status');
        $this->createIndex('idx_report_archived', 'report', 'archived_at');
        $this->addUserForeignKeys('report', ['created_by', 'updated_by', 'archived_by', 'generated_by', 'approved_by_id']);
    }

    public function safeDown()
    {
        $this->dropTable('report');
    }
}
