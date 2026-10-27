<?php

use app\components\Migration;
use app\modules\attack\components\CatalogueImporter;

/**
 * Loads the bundled MITRE ATT&CK Enterprise snapshot (data/attack). Later
 * releases are loaded with `php yii attack/update` (or attack/import <file>).
 */
class m261019_000002_import_attack_catalogue extends Migration
{
    public function safeUp()
    {
        $stats = (new CatalogueImporter($this->db))->import(CatalogueImporter::readSnapshot());
        echo sprintf("    > ATT&CK: %d tactics, %d techniques, %d mitigations\n", $stats['tactics'], $stats['techniques'], $stats['mitigations']);
    }

    public function safeDown()
    {
        foreach (['decision_attack_mitigation', 'risk_attack_technique', 'incident_attack_technique', 'attack_mitigation_technique',
                     'attack_technique_tactic', 'attack_release'] as $t) {
            $this->delete($t);
        }
        $this->update('attack_technique', ['parent_id' => null]);
        foreach (['attack_mitigation', 'attack_technique', 'attack_tactic'] as $t) {
            $this->delete($t);
        }
    }
}
