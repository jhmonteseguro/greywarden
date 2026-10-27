<?php

namespace app\modules\attack\models;

use yii\db\ActiveRecord;

/**
 * ATT&CK tactic (the adversary's goal: TA0001 Initial Access ...).
 *
 * @property int $id
 * @property string $external_id
 * @property string $shortname
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_deprecated
 *
 * @property-read AttackTechnique[] $techniques
 */
class AttackTactic extends ActiveRecord
{
    use CatalogueItem;

    public static function tableName(): string
    {
        return 'attack_tactic';
    }

    protected static function mitrePath(): string
    {
        return 'tactics';
    }

    public function getViewUrl(): array
    {
        return ['/attack/technique/index', '#' => $this->external_id];
    }

    public function getTechniques()
    {
        return $this->hasMany(AttackTechnique::class, ['id' => 'technique_id'])->viaTable('attack_technique_tactic', ['tactic_id' => 'id']);
    }
}
