<?php

use app\components\Migration;
use app\modules\control\components\FrameworkCatalogue;

/**
 * Loads the SOC 2 Trust Services Criteria (data/frameworks/soc2-tsc-2017.php).
 * The import upserts every bundled catalogue, so ISO 27001 and Cyber
 * Essentials keep their applicability and control links.
 *
 * Nothing is stored for the framework switches in Admin › Settings: each
 * framework is on until an admin turns it off.
 */
class m261028_000001_import_soc2_catalogue extends Migration
{
    public function safeUp()
    {
        $n = FrameworkCatalogue::import($this->db)[FrameworkCatalogue::SOC2];
        echo sprintf("    > %s: %d requirements\n", FrameworkCatalogue::label(FrameworkCatalogue::SOC2), $n);
    }

    public function safeDown()
    {
        $ids = (new \yii\db\Query())->select('id')->from('framework_requirement')->where(['framework' => FrameworkCatalogue::SOC2])->column($this->db);
        $this->delete('control_framework_requirement', ['requirement_id' => $ids ?: [0]]);
        $this->delete('framework_requirement', ['framework' => FrameworkCatalogue::SOC2]);
        $this->delete('setting', ['like', 'name', 'framework.%', false]);
    }
}
