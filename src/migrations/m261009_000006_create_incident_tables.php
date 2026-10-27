<?php

use app\components\Migration;

class m261009_000006_create_incident_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('incident', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(20)->notNull()->unique(),
            'title' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'category_id' => $this->integer()->null(),
            'severity' => $this->string(16)->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('new'),
            'occurred_at' => $this->dateTime()->null(),
            'detected_at' => $this->dateTime()->notNull(),
            'reported_at' => $this->dateTime()->null(),
            'reported_by' => $this->string(128)->null(),
            'assigned_to_id' => $this->integer()->null(),
            'personal_data_involved' => $this->boolean()->notNull()->defaultValue(false),
            'regulator_notification_required' => $this->boolean()->notNull()->defaultValue(false),
            'regulator_notified_at' => $this->dateTime()->null(),
            'subjects_notified_at' => $this->dateTime()->null(),
            'regulator_reference' => $this->string(64)->null(),
            'containment_actions' => $this->text()->null(),
            'recovery_actions' => $this->text()->null()->comment('Eradication / recovery actions'),
            'root_cause' => $this->text()->null(),
            'lessons_learned' => $this->text()->null(),
            'contained_at' => $this->dateTime()->null(),
            'closed_at' => $this->dateTime()->null(),
        ] + $this->archiveColumns() + $this->auditColumns(), $this->tableOptions());

        $this->createIndex('idx_incident_status', 'incident', 'status');
        $this->createIndex('idx_incident_severity', 'incident', 'severity');
        $this->createIndex('idx_incident_detected', 'incident', 'detected_at');
        $this->createIndex('idx_incident_assigned', 'incident', 'assigned_to_id');
        $this->createIndex('idx_incident_regulator', 'incident', ['regulator_notification_required', 'regulator_notified_at']);
        $this->createIndex('idx_incident_archived', 'incident', 'archived_at');
        $this->addForeignKey('fk_incident_category', 'incident', 'category_id', 'lookup', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_incident_assigned_to', 'incident', 'assigned_to_id', 'user', 'id', 'SET NULL', 'CASCADE');
        $this->addUserForeignKeys('incident', ['created_by', 'updated_by', 'archived_by']);

        $this->createTable('incident_timeline', [
            'id' => $this->primaryKey(),
            'incident_id' => $this->integer()->notNull(),
            'entry_at' => $this->dateTime()->notNull(),
            'entry_type' => $this->string(16)->notNull()->defaultValue('note'),
            'body' => $this->text()->notNull(),
        ] + $this->auditColumns(), $this->tableOptions());
        $this->createIndex('idx_incident_timeline_incident', 'incident_timeline', ['incident_id', 'entry_at']);
        $this->addForeignKey('fk_incident_timeline_incident', 'incident_timeline', 'incident_id', 'incident', 'id', 'CASCADE', 'CASCADE');
        $this->addUserForeignKeys('incident_timeline');
    }

    public function safeDown()
    {
        $this->dropTable('incident_timeline');
        $this->dropTable('incident');
    }
}
