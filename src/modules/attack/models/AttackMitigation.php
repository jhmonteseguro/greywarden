<?php

namespace app\modules\attack\models;

use app\modules\decision\models\Decision;
use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * ATT&CK mitigation (M1017 User Training ...).
 *
 * @property int $id
 * @property string $external_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_deprecated
 *
 * @property-read AttackTechnique[] $techniques
 * @property-read Decision[] $decisions
 */
class AttackMitigation extends ActiveRecord
{
    use CatalogueItem;

    public static function tableName(): string
    {
        return 'attack_mitigation';
    }

    protected static function mitrePath(): string
    {
        return 'mitigations';
    }

    public function getViewUrl(): array
    {
        return ['/attack/mitigation/view', 'id' => $this->external_id];
    }

    public function getTechniques()
    {
        return $this->hasMany(AttackTechnique::class, ['id' => 'technique_id'])->viaTable('attack_mitigation_technique', ['mitigation_id' => 'id'])
            ->orderBy(['attack_technique.external_id' => SORT_ASC]);
    }

    public function getDecisions()
    {
        return $this->hasMany(Decision::class, ['id' => 'decision_id'])->viaTable('decision_attack_mitigation', ['mitigation_id' => 'id']);
    }

    /** Options for decision forms: current mitigations plus already linked ones. */
    public static function selectOptions(array $includeIds = []): array
    {
        $rows = static::find()->select(['id', 'external_id', 'name'])
            ->where(['is_deprecated' => 0])->orFilterWhere(['id' => $includeIds ?: null])
            ->orderBy(['external_id' => SORT_ASC])->asArray()->all();
        $options = [];
        foreach ($rows as $row) {
            $options[$row['id']] = $row['external_id'] . ' · ' . $row['name'];
        }
        return $options;
    }

    /**
     * @param string $term ATT&CK id (M1032) or part of a name
     * @return int[]
     */
    public static function idsMatching(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        return array_map('intval', (new Query())->select('id')->from(static::tableName())
            ->where(preg_match('/^m\d{4}$/i', $term) ? ['external_id' => strtoupper($term)] : ['or', ['like', 'name', $term], ['like', 'external_id', $term]])
            ->column(static::getDb()));
    }
}
