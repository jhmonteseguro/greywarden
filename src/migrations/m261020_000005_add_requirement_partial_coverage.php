<?php

use app\components\Migration;

/**
 * Lets a requirement record that its linked controls cover only part of it
 * (e.g. MFA on Google accounts only, against "MFA everywhere"). Such a
 * requirement is capped at "partly met" however good the controls are.
 */
class m261020_000005_add_requirement_partial_coverage extends Migration
{
    public function safeUp()
    {
        $this->addColumn('framework_requirement', 'partial_coverage', $this->boolean()->notNull()->defaultValue(false)->after('justification'));
        $this->addColumn('framework_requirement', 'coverage_gap', $this->text()->null()->comment('What the linked controls do not cover')->after('partial_coverage'));
    }

    public function safeDown()
    {
        $this->dropColumn('framework_requirement', 'coverage_gap');
        $this->dropColumn('framework_requirement', 'partial_coverage');
    }
}
