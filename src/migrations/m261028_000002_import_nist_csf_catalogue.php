<?php

use app\components\Migration;
use app\modules\control\components\FrameworkCatalogue;

/**
 * Loads the NIST CSF 2.0 Core (data/frameworks/nist-csf-2.0.php). The import
 * upserts every bundled catalogue, so the other frameworks keep their
 * applicability and control links. It is switched on like the others until an
 * admin turns it off in Admin › Settings.
 */
class m261028_000002_import_nist_csf_catalogue extends Migration
{
    public function safeUp()
    {
        $n = FrameworkCatalogue::import($this->db)[FrameworkCatalogue::NIST_CSF];
        echo sprintf("    > %s: %d requirements\n", FrameworkCatalogue::label(FrameworkCatalogue::NIST_CSF), $n);
    }

    public function safeDown()
    {
        $ids = (new \yii\db\Query())->select('id')->from('framework_requirement')->where(['framework' => FrameworkCatalogue::NIST_CSF])->column($this->db);
        $this->delete('control_framework_requirement', ['requirement_id' => $ids ?: [0]]);
        $this->delete('framework_requirement', ['framework' => FrameworkCatalogue::NIST_CSF]);
        $this->delete('setting', ['name' => FrameworkCatalogue::settingName(FrameworkCatalogue::NIST_CSF)]);
    }
}
