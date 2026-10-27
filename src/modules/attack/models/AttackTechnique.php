<?php

namespace app\modules\attack\models;

use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * ATT&CK technique or sub-technique (T1566, T1566.001 ...).
 *
 * @property int $id
 * @property string $external_id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $description
 * @property string|null $platforms
 * @property bool $is_subtechnique
 * @property bool $is_deprecated
 * @property string|null $revoked_by
 * @property string|null $modified_on
 *
 * @property-read AttackTechnique|null $parent
 * @property-read AttackTechnique[] $subtechniques
 * @property-read AttackTactic[] $tactics
 * @property-read AttackMitigation[] $mitigations
 * @property-read Incident[] $incidents
 * @property-read Risk[] $risks
 */
class AttackTechnique extends ActiveRecord
{
    use CatalogueItem;

    public static function tableName(): string
    {
        return 'attack_technique';
    }

    protected static function mitrePath(): string
    {
        return 'techniques';
    }

    public function getViewUrl(): array
    {
        return ['/attack/technique/view', 'id' => $this->external_id];
    }

    /** "Phishing: Spearphishing Attachment" for sub-techniques, as MITRE writes them */
    public function getTitleValue(): string
    {
        return $this->parent_id && $this->parent ? $this->parent->name . ': ' . $this->name : (string) $this->name;
    }

    /** @return string[] */
    public function getPlatformList(): array
    {
        return $this->platforms ? array_map('trim', explode(',', $this->platforms)) : [];
    }

    public function getParent()
    {
        return $this->hasOne(self::class, ['id' => 'parent_id']);
    }

    public function getSubtechniques()
    {
        return $this->hasMany(self::class, ['parent_id' => 'id'])->orderBy(['external_id' => SORT_ASC]);
    }

    public function getTactics()
    {
        return $this->hasMany(AttackTactic::class, ['id' => 'tactic_id'])->viaTable('attack_technique_tactic', ['technique_id' => 'id'])
            ->orderBy(['attack_tactic.sort_order' => SORT_ASC]);
    }

    public function getMitigations()
    {
        return $this->hasMany(AttackMitigation::class, ['id' => 'mitigation_id'])->viaTable('attack_mitigation_technique', ['technique_id' => 'id'])
            ->orderBy(['attack_mitigation.external_id' => SORT_ASC]);
    }

    public function getIncidents()
    {
        return $this->hasMany(Incident::class, ['id' => 'incident_id'])->viaTable('incident_attack_technique', ['technique_id' => 'id']);
    }

    public function getRisks()
    {
        return $this->hasMany(Risk::class, ['id' => 'risk_id'])->viaTable('risk_attack_technique', ['technique_id' => 'id']);
    }

    /**
     * Options for technique pickers: current techniques plus already linked ones,
     * labelled "T1566.001 · Phishing: Spearphishing Attachment".
     */
    public static function selectOptions(array $includeIds = []): array
    {
        $rows = (new Query())
            ->select(['t.id', 't.external_id', 't.name', 'parent' => 'p.name', 't.is_deprecated'])
            ->from(['t' => 'attack_technique'])
            ->leftJoin(['p' => 'attack_technique'], 'p.id = t.parent_id')
            ->where(['t.is_deprecated' => 0])
            ->orFilterWhere(['t.id' => $includeIds ?: null])
            ->all(static::getDb());
        usort($rows, static fn($a, $b) => strnatcmp($a['external_id'], $b['external_id']));
        $options = [];
        foreach ($rows as $row) {
            $options[$row['id']] = $row['external_id'] . ' · ' . ($row['parent'] ? $row['parent'] . ': ' : '') . $row['name']
                . ($row['is_deprecated'] ? ' (deprecated)' : '');
        }
        return $options;
    }

    /**
     * Ids of the given techniques plus all their sub-techniques, for "T1566 and below" filters.
     * @param string $term ATT&CK id prefix (T1566) or part of a name
     * @return int[]
     */
    public static function idsMatching(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $query = (new Query())->select('t.id')->from(['t' => 'attack_technique'])
            ->leftJoin(['p' => 'attack_technique'], 'p.id = t.parent_id');
        if (preg_match('/^t\d{4}(\.\d{1,3})?$/i', $term)) {
            $id = strtoupper($term);
            $query->where(['or', ['t.external_id' => $id], ['like', 't.external_id', $id . '.%', false]]);
        } else {
            $query->where(['or', ['like', 't.name', $term], ['like', 'p.name', $term], ['like', 't.external_id', $term]]);
        }
        return array_map('intval', $query->column(static::getDb()));
    }
}
