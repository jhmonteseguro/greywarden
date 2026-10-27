<?php

namespace app\modules\attack\components;

/**
 * Turns a MITRE ATT&CK STIX 2.1 bundle (attack-stix-data) into Greywarden's
 * compact catalogue format, which is also the format of the bundled snapshot
 * (data/attack/enterprise-attack.json.gz):
 *
 *   domain, version, modified, source
 *   tactics[]     {id, shortname, name, description}             (matrix order)
 *   techniques[]  {id, name, description, tactics[], platforms[], subtechnique, deprecated, revoked_by, modified}
 *   mitigations[] {id, name, description, deprecated}
 *   mitigates[]   [mitigationId, techniqueId]
 */
final class StixParser
{
    public static function parse(array $bundle, ?string $source = null): array
    {
        $objects = [];
        foreach ($bundle['objects'] ?? [] as $o) {
            if (isset($o['id'], $o['type'])) {
                $objects[$o['id']] = $o;
            }
        }

        $collection = self::firstOfType($objects, 'x-mitre-collection');
        $matrix = self::firstOfType($objects, 'x-mitre-matrix');
        if ($matrix === null) {
            throw new \InvalidArgumentException('Not an ATT&CK bundle: no x-mitre-matrix object found.');
        }

        // Tactics, in the order the matrix lists them
        $tactics = [];
        foreach ($matrix['tactic_refs'] ?? [] as $ref) {
            $t = $objects[$ref] ?? null;
            $id = $t ? self::externalId($t) : null;
            if ($id === null) {
                continue;
            }
            $tactics[] = [
                'id' => $id,
                'shortname' => (string) ($t['x_mitre_shortname'] ?? ''),
                'name' => (string) $t['name'],
                'description' => self::cleanText($t['description'] ?? ''),
            ];
        }

        // Revocations: old STIX id => replacement STIX id
        $revokedBy = [];
        foreach ($objects as $o) {
            if ($o['type'] === 'relationship' && ($o['relationship_type'] ?? '') === 'revoked-by') {
                $revokedBy[$o['source_ref']] = $o['target_ref'];
            }
        }

        $techniques = [];
        $techniqueIds = [];
        foreach ($objects as $stixId => $o) {
            if ($o['type'] !== 'attack-pattern' || ($id = self::externalId($o)) === null) {
                continue;
            }
            $phases = [];
            foreach ($o['kill_chain_phases'] ?? [] as $p) {
                if (($p['kill_chain_name'] ?? '') === 'mitre-attack') {
                    $phases[] = $p['phase_name'];
                }
            }
            $replacement = isset($revokedBy[$stixId], $objects[$revokedBy[$stixId]]) ? self::externalId($objects[$revokedBy[$stixId]]) : null;
            $techniques[$id] = [
                'id' => $id,
                'name' => (string) $o['name'],
                'description' => self::cleanText($o['description'] ?? ''),
                'tactics' => $phases,
                'platforms' => array_values($o['x_mitre_platforms'] ?? []),
                'subtechnique' => (bool) ($o['x_mitre_is_subtechnique'] ?? str_contains($id, '.')),
                'deprecated' => !empty($o['revoked']) || !empty($o['x_mitre_deprecated']),
                'revoked_by' => $replacement,
                'modified' => isset($o['modified']) ? substr((string) $o['modified'], 0, 10) : null,
            ];
            $techniqueIds[$stixId] = $id;
        }
        ksort($techniques, SORT_NATURAL);

        $mitigations = [];
        $mitigationIds = [];
        foreach ($objects as $stixId => $o) {
            // Old-style course-of-action objects carry technique ids (T1234); only M-ids are real mitigations
            if ($o['type'] !== 'course-of-action' || ($id = self::externalId($o)) === null || !preg_match('/^M\d{4}$/', $id)) {
                continue;
            }
            $mitigations[$id] = [
                'id' => $id,
                'name' => (string) $o['name'],
                'description' => self::cleanText($o['description'] ?? ''),
                'deprecated' => !empty($o['revoked']) || !empty($o['x_mitre_deprecated']),
            ];
            $mitigationIds[$stixId] = $id;
        }
        ksort($mitigations, SORT_NATURAL);

        $mitigates = [];
        foreach ($objects as $o) {
            if ($o['type'] !== 'relationship' || ($o['relationship_type'] ?? '') !== 'mitigates'
                || !empty($o['revoked']) || !empty($o['x_mitre_deprecated'])) {
                continue;
            }
            $m = $mitigationIds[$o['source_ref']] ?? null;
            $t = $techniqueIds[$o['target_ref']] ?? null;
            if ($m !== null && $t !== null) {
                $mitigates[$m . '|' . $t] = [$m, $t];
            }
        }
        ksort($mitigates, SORT_NATURAL);

        return [
            'domain' => self::externalId($matrix) ?? 'enterprise-attack',
            'version' => (string) ($collection['x_mitre_version'] ?? ''),
            'modified' => isset($collection['modified']) ? substr((string) $collection['modified'], 0, 10) : null,
            'source' => $source,
            'tactics' => $tactics,
            'techniques' => array_values($techniques),
            'mitigations' => array_values($mitigations),
            'mitigates' => array_values($mitigates),
        ];
    }

    /** The ATT&CK id (TA0001, T1566.001, M1017...) from external_references. */
    public static function externalId(array $object): ?string
    {
        foreach ($object['external_references'] ?? [] as $ref) {
            if (($ref['source_name'] ?? '') === 'mitre-attack' && !empty($ref['external_id'])) {
                return (string) $ref['external_id'];
            }
        }
        return null;
    }

    /** Strip citations, flatten markdown links and normalise blank lines. */
    public static function cleanText(string $text): string
    {
        $text = preg_replace('/\s*\(Citation:[^)]*\)/u', '', $text);
        $text = preg_replace('/\[([^\]]+)\]\((?:https?:\/\/)[^)]*\)/u', '$1', $text);
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    private static function firstOfType(array $objects, string $type): ?array
    {
        foreach ($objects as $o) {
            if ($o['type'] === $type) {
                return $o;
            }
        }
        return null;
    }
}
