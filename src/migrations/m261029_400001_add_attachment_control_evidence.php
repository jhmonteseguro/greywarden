<?php

use app\components\Migration;

/**
 * Marks files attached from a control's Evidence field, so the control lists
 * them as its evidence (they stay on the Files tab like any other file).
 */
class m261029_400001_add_attachment_control_evidence extends Migration
{
    public function safeUp()
    {
        $this->addColumn('attachment', 'is_evidence', $this->boolean()->notNull()->defaultValue(false)->after('control_test_id')->comment('Attached as the control\'s evidence'));
    }

    public function safeDown()
    {
        $this->dropColumn('attachment', 'is_evidence');
    }
}
