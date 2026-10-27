<?php

use app\components\Migration;

/**
 * Target risk: the score a risk is expected to reach once its planned
 * treatment is in place. Residual stays the current score with the controls
 * that actually operate today.
 */
class m261020_000006_add_risk_target_score extends Migration
{
    public function safeUp()
    {
        $this->addColumn('risk', 'target_likelihood', $this->tinyInteger()->null()->after('residual_score'));
        $this->addColumn('risk', 'target_impact', $this->tinyInteger()->null()->after('target_likelihood'));
        $this->addColumn('risk', 'target_score', $this->tinyInteger()->null()->after('target_impact'));
        $this->createIndex('idx_risk_target_score', 'risk', ['target_likelihood', 'target_impact']);
    }

    public function safeDown()
    {
        $this->dropIndex('idx_risk_target_score', 'risk');
        $this->dropColumn('risk', 'target_score');
        $this->dropColumn('risk', 'target_impact');
        $this->dropColumn('risk', 'target_likelihood');
    }
}
