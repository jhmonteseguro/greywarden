<?php

namespace app\modules\attack\models;

use yii\db\ActiveRecord;

/**
 * ATT&CK threat group (G0016 APT29 ...): who it is, what else it is called and what it does.
 *
 * @property int $id
 * @property string $external_id
 * @property string $name
 * @property string|null $aliases JSON
 * @property string|null $description
 * @property bool $is_deprecated
 * @property string|null $revoked_by
 * @property string|null $modified_on
 *
 * @property-read AttackTechnique[] $techniques
 */
class AttackGroup extends ActiveRecord
{
    use CatalogueItem;

    /** Stories naming it, the latest of them and its techniques, when selected by ActorSearch */
    public $story_count;
    public $last_named_at;
    public $technique_count;

    public static function tableName(): string
    {
        return 'attack_group';
    }

    protected static function mitrePath(): string
    {
        return 'groups';
    }

    public function getViewUrl(): array
    {
        return ['/threat/actor/view', 'id' => $this->external_id];
    }

    /** @return string[] */
    public function getAliasList(): array
    {
        return json_decode((string) $this->aliases, true) ?: [];
    }

    public function getTechniques()
    {
        return $this->hasMany(AttackTechnique::class, ['id' => 'technique_id'])->viaTable('attack_group_technique', ['group_id' => 'id']);
    }
}
