<?php

use app\components\Migration;
use app\modules\control\components\FrameworkCatalogue;

/**
 * Loads the NCSC Cyber Assessment Framework 4.0
 * (data/frameworks/ncsc-caf-4.0.php). The import upserts every bundled
 * catalogue, so the other frameworks keep their applicability and control links. It is switched on like the others until an
 * admin turns it off in Admin › Settings.
 */
class m261028_000003_import_ncsc_caf_catalogue extends Migration
{
    public function safeUp()
    {
        $n = FrameworkCatalogue::import($this->db)[FrameworkCatalogue::NCSC_CAF];
        echo sprintf("    > %s: %d requirements\n", FrameworkCatalogue::label(FrameworkCatalogue::NCSC_CAF), $n);
    }

    public function safeDown()
    {
        $ids = (new \yii\db\Query())->select('id')->from('framework_requirement')->where(['framework' => FrameworkCatalogue::NCSC_CAF])->column($this->db);
        $this->delete('control_framework_requirement', ['requirement_id' => $ids ?: [0]]);
        $this->delete('framework_requirement', ['framework' => FrameworkCatalogue::NCSC_CAF]);
        $this->delete('setting', ['name' => FrameworkCatalogue::settingName(FrameworkCatalogue::NCSC_CAF)]);
    }
}
