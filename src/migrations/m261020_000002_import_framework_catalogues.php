<?php

use app\components\Migration;
use app\modules\control\components\FrameworkCatalogue;

/**
 * Loads the bundled ISO/IEC 27001:2022 Annex A and Cyber Essentials
 * requirements (data/frameworks).
 */
class m261020_000002_import_framework_catalogues extends Migration
{
    public function safeUp()
    {
        foreach (FrameworkCatalogue::import($this->db) as $framework => $n) {
            echo sprintf("    > %s: %d requirements\n", FrameworkCatalogue::label($framework), $n);
        }
    }

    public function safeDown()
    {
        $this->delete('control_framework_requirement');
        $this->delete('framework_requirement');
    }
}
