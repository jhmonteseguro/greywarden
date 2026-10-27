<?php

namespace app\modules\attack\components;

use app\components\Actor;
use Yii;
use yii\db\Connection;
use yii\db\Query;

/**
 * Loads a parsed catalogue (see StixParser) into the attack_* tables.
 *
 * Tactics, techniques and mitigations are upserted by their ATT&CK id. Rows
 * missing from the new release are flagged deprecated rather than deleted, so
 * links from incidents, risks and decisions survive. The catalogue-internal
 * relations (technique -> tactic, mitigation -> technique) are rebuilt.
 */
final class CatalogueImporter
{
    public const SNAPSHOT = '@app/data/attack/enterprise-attack.json.gz';
    public const STIX_URL = 'https://raw.githubusercontent.com/mitre-attack/attack-stix-data/master/enterprise-attack/enterprise-attack.json';

    public function __construct(private ?Connection $db = null)
    {
        $this->db ??= Yii::$app->db;
    }

    /** Read the bundled (or any) compact snapshot; .gz files are decompressed. */
    public static function readSnapshot(?string $path = null): array
    {
        $path = Yii::getAlias($path ?? self::SNAPSHOT);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read ATT&CK snapshot {$path}");
        }
        if (str_ends_with($path, '.gz')) {
            $raw = gzdecode($raw);
        }
        $data = json_decode((string) $raw, true, 64, JSON_THROW_ON_ERROR);
        if (!isset($data['tactics'], $data['techniques'], $data['mitigations'], $data['mitigates'])) {
            throw new \RuntimeException("{$path} is not a Greywarden ATT&CK snapshot");
        }
        return $data;
    }

    public static function writeSnapshot(array $catalogue, ?string $path = null): string
    {
        $path = Yii::getAlias($path ?? self::SNAPSHOT);
        $json = json_encode($catalogue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, str_ends_with($path, '.gz') ? gzencode($json, 9) : $json);
        return $path;
    }

    /**
     * @return array{tactics:int, techniques:int, mitigations:int, mitigates:int, deprecated:int}
     */
    public function import(array $catalogue): array
    {
        $tx = $this->db->beginTransaction();
        try {
            $tacticIds = $this->upsert('attack_tactic', array_map(static fn(array $t, int $i) => [
                'external_id' => $t['id'],
                'shortname' => $t['shortname'],
                'name' => $t['name'],
                'description' => $t['description'] ?: null,
                'sort_order' => ($i + 1) * 10,
                'is_deprecated' => 0,
            ], $catalogue['tactics'], array_keys($catalogue['tactics'])));

            $techniqueIds = $this->upsert('attack_technique', array_map(static fn(array $t) => [
                'external_id' => $t['id'],
                'name' => $t['name'],
                'description' => $t['description'] ?: null,
                'platforms' => implode(', ', $t['platforms']) ?: null,
                'is_subtechnique' => $t['subtechnique'] ? 1 : 0,
                'is_deprecated' => $t['deprecated'] ? 1 : 0,
                'revoked_by' => $t['revoked_by'] ?? null,
                'modified_on' => $t['modified'] ?? null,
            ], $catalogue['techniques']));

            $mitigationIds = $this->upsert('attack_mitigation', array_map(static fn(array $m) => [
                'external_id' => $m['id'],
                'name' => $m['name'],
                'description' => $m['description'] ?: null,
                'is_deprecated' => $m['deprecated'] ? 1 : 0,
            ], $catalogue['mitigations']));

            // Anything not in this release is deprecated (kept for existing links)
            $deprecated = 0;
            foreach (['attack_tactic' => $tacticIds, 'attack_technique' => $techniqueIds, 'attack_mitigation' => $mitigationIds] as $table => $ids) {
                $deprecated += $this->db->createCommand()->update($table, ['is_deprecated' => 1],
                    ['and', ['is_deprecated' => 0], ['not in', 'external_id', array_keys($ids) ?: ['']]])->execute();
            }

            // Sub-technique -> parent (T1566.001 -> T1566)
            foreach ($techniqueIds as $externalId => $id) {
                $parent = str_contains($externalId, '.') ? ($techniqueIds[strstr($externalId, '.', true)] ?? null) : null;
                $this->db->createCommand()->update('attack_technique', ['parent_id' => $parent], ['id' => $id])->execute();
            }

            $this->db->createCommand()->delete('attack_technique_tactic', ['technique_id' => array_values($techniqueIds)])->execute();
            $tacticByShortname = array_column($catalogue['tactics'], 'id', 'shortname');
            $rows = [];
            foreach ($catalogue['techniques'] as $t) {
                foreach (array_unique($t['tactics']) as $shortname) {
                    if (isset($tacticByShortname[$shortname])) {
                        $rows[] = [$techniqueIds[$t['id']], $tacticIds[$tacticByShortname[$shortname]]];
                    }
                }
            }
            $this->batchInsert('attack_technique_tactic', ['technique_id', 'tactic_id'], $rows);

            $this->db->createCommand()->delete('attack_mitigation_technique')->execute();
            $rows = [];
            foreach ($catalogue['mitigates'] as [$m, $t]) {
                if (isset($mitigationIds[$m], $techniqueIds[$t])) {
                    $rows[] = [$mitigationIds[$m], $techniqueIds[$t]];
                }
            }
            $this->batchInsert('attack_mitigation_technique', ['mitigation_id', 'technique_id'], $rows);

            $this->db->createCommand()->insert('attack_release', [
                'domain' => $catalogue['domain'] ?? 'enterprise-attack',
                'version' => $catalogue['version'] ?? '',
                'released_at' => $catalogue['modified'] ?? null,
                'source' => isset($catalogue['source']) ? mb_substr((string) $catalogue['source'], 0, 255) : null,
                'tactic_count' => count($catalogue['tactics']),
                'technique_count' => count(array_filter($catalogue['techniques'], static fn($t) => !$t['deprecated'])),
                'mitigation_count' => count(array_filter($catalogue['mitigations'], static fn($m) => !$m['deprecated'])),
                'imported_at' => Actor::now(),
            ])->execute();

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
        if (Yii::$app->has('cache')) {
            Yii::$app->cache->delete(Catalogue::CACHE_KEY);
        }

        return [
            'tactics' => count($tacticIds),
            'techniques' => count($techniqueIds),
            'mitigations' => count($mitigationIds),
            'mitigates' => count($rows),
            'deprecated' => $deprecated,
        ];
    }

    /**
     * Insert or update rows keyed by external_id.
     * @return array<string,int> external_id => primary key
     */
    private function upsert(string $table, array $rows): array
    {
        $existing = (new Query())->select(['id', 'external_id'])->from($table)->indexBy('external_id')->column($this->db);
        $insert = [];
        foreach ($rows as $row) {
            if (isset($existing[$row['external_id']])) {
                $this->db->createCommand()->update($table, $row, ['id' => $existing[$row['external_id']]])->execute();
            } else {
                $insert[] = $row;
            }
        }
        if ($insert) {
            $this->batchInsert($table, array_keys($insert[0]), array_map('array_values', $insert));
        }
        $ids = (new Query())->select(['id', 'external_id'])->from($table)
            ->where(['external_id' => array_column($rows, 'external_id')])->indexBy('external_id')->column($this->db);
        return array_map('intval', $ids);
    }

    private function batchInsert(string $table, array $columns, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->createCommand()->batchInsert($table, $columns, $chunk)->execute();
        }
    }
}
