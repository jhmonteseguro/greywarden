<?php

namespace tests\unit;

use app\models\AuditLog;
use app\models\Lookup;
use app\modules\attack\components\AttackText;
use app\modules\attack\components\CatalogueImporter;
use app\modules\attack\components\Coverage;
use app\modules\attack\components\DeviceAttack;
use app\modules\attack\components\StixParser;
use app\modules\attack\models\AttackMitigation;
use app\modules\attack\models\AttackTechnique;
use app\modules\control\models\Control;
use app\modules\control\models\ControlTest;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use tests\DbTestCase;
use yii\db\Query;

class AttackTest extends DbTestCase
{
    private static function ref(string $id, string $path): array
    {
        return [['source_name' => 'mitre-attack', 'external_id' => $id, 'url' => "https://attack.mitre.org/{$path}/" . str_replace('.', '/', $id)]];
    }

    /** A minimal STIX bundle: 2 tactics, a technique + sub-technique, a revoked technique and a mitigation. */
    private static function bundle(): array
    {
        return ['type' => 'bundle', 'objects' => [
            ['type' => 'x-mitre-collection', 'id' => 'x-mitre-collection--1', 'x_mitre_version' => '99.1', 'modified' => '2026-10-01T00:00:00.000Z'],
            ['type' => 'x-mitre-matrix', 'id' => 'x-mitre-matrix--1', 'external_references' => self::ref('enterprise-attack', 'matrices'),
                'tactic_refs' => ['x-mitre-tactic--ex', 'x-mitre-tactic--ia']],
            ['type' => 'x-mitre-tactic', 'id' => 'x-mitre-tactic--ia', 'name' => 'Initial Access', 'x_mitre_shortname' => 'initial-access',
                'description' => 'Get in.', 'external_references' => self::ref('TA9001', 'tactics')],
            ['type' => 'x-mitre-tactic', 'id' => 'x-mitre-tactic--ex', 'name' => 'Execution', 'x_mitre_shortname' => 'execution',
                'description' => 'Run code.', 'external_references' => self::ref('TA9002', 'tactics')],
            ['type' => 'attack-pattern', 'id' => 'attack-pattern--p', 'name' => 'Test Phishing', 'x_mitre_platforms' => ['Windows', 'SaaS'],
                'description' => "First para (Citation: Someone 2020).\n\nSee [the docs](https://example.org/x) for <code>cmd.exe</code>.",
                'kill_chain_phases' => [['kill_chain_name' => 'mitre-attack', 'phase_name' => 'initial-access']],
                'external_references' => self::ref('T9001', 'techniques'), 'modified' => '2026-09-01T00:00:00Z'],
            ['type' => 'attack-pattern', 'id' => 'attack-pattern--s', 'name' => 'Attachment', 'x_mitre_is_subtechnique' => true,
                'kill_chain_phases' => [['kill_chain_name' => 'mitre-attack', 'phase_name' => 'initial-access'], ['kill_chain_name' => 'mitre-attack', 'phase_name' => 'execution']],
                'external_references' => self::ref('T9001.001', 'techniques')],
            ['type' => 'attack-pattern', 'id' => 'attack-pattern--old', 'name' => 'Old Thing', 'revoked' => true,
                'external_references' => self::ref('T9002', 'techniques')],
            ['type' => 'relationship', 'id' => 'relationship--r', 'relationship_type' => 'revoked-by', 'source_ref' => 'attack-pattern--old', 'target_ref' => 'attack-pattern--p'],
            ['type' => 'course-of-action', 'id' => 'course-of-action--m', 'name' => 'Test Training', 'external_references' => self::ref('M9001', 'mitigations')],
            ['type' => 'course-of-action', 'id' => 'course-of-action--legacy', 'name' => 'Legacy', 'external_references' => self::ref('T9001', 'techniques')],
            ['type' => 'relationship', 'id' => 'relationship--m', 'relationship_type' => 'mitigates', 'source_ref' => 'course-of-action--m', 'target_ref' => 'attack-pattern--s'],
            ['type' => 'relationship', 'id' => 'relationship--u', 'relationship_type' => 'uses', 'source_ref' => 'intrusion-set--x', 'target_ref' => 'attack-pattern--p'],
        ]];
    }

    public function testStixParserProducesCompactCatalogue(): void
    {
        $c = StixParser::parse(self::bundle(), 'test');
        $this->assertSame('99.1', $c['version']);
        $this->assertSame('2026-10-01', $c['modified']);
        // Matrix order, not object order
        $this->assertSame(['TA9002', 'TA9001'], array_column($c['tactics'], 'id'));
        $byId = array_column($c['techniques'], null, 'id');
        $this->assertSame(['T9001', 'T9001.001', 'T9002'], array_keys($byId));
        $this->assertSame(['initial-access', 'execution'], $byId['T9001.001']['tactics']);
        $this->assertTrue($byId['T9001.001']['subtechnique']);
        $this->assertTrue($byId['T9002']['deprecated']);
        $this->assertSame('T9001', $byId['T9002']['revoked_by']);
        $this->assertSame("First para.\n\nSee the docs for <code>cmd.exe</code>.", $byId['T9001']['description']);
        // Only M-ids are mitigations; only "mitigates" relationships are kept
        $this->assertSame(['M9001'], array_column($c['mitigations'], 'id'));
        $this->assertSame([['M9001', 'T9001.001']], $c['mitigates']);
    }

    public function testAttackTextEscapesHtmlButKeepsCode(): void
    {
        $html = AttackText::render("Run <code>whoami</code> then <script>alert(1)</script>.\n\n* one\n* two");
        $this->assertStringContainsString('<code class="gw-code">whoami</code>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<ul><li>one</li><li>two</li></ul>', $html);
        $this->assertSame('Short…', AttackText::summary("Short text here\n\nSecond", 6));
    }

    public function testImporterUpsertsLinksParentsAndDeprecatesMissingEntries(): void
    {
        $importer = new CatalogueImporter();
        $catalogue = StixParser::parse(self::bundle(), 'test');
        $stats = $importer->import($catalogue);
        $this->assertSame(3, $stats['techniques']);
        $this->assertSame(1, $stats['mitigates']);

        $sub = AttackTechnique::findByExternalId('T9001.001');
        $this->assertSame('T9001', $sub->parent->external_id);
        $this->assertSame('Test Phishing: Attachment', $sub->getTitleValue());
        $this->assertSame(['TA9002', 'TA9001'], array_map(static fn($t) => $t->external_id, $sub->tactics));
        $this->assertSame(['M9001'], array_map(static fn($m) => $m->external_id, $sub->mitigations));
        $this->assertSame('https://attack.mitre.org/techniques/T9001/001/', $sub->getMitreUrl());
        // The bundled catalogue's techniques are not in this release, so they are now deprecated
        $this->assertTrue((bool) AttackTechnique::findByExternalId('T1566')->is_deprecated);

        // Re-import without the sub-technique: same row ids, sub-technique flagged deprecated, not deleted
        $id = $sub->id;
        $catalogue['techniques'] = array_values(array_filter($catalogue['techniques'], static fn($t) => $t['id'] !== 'T9001.001'));
        $catalogue['mitigates'] = [];
        $importer->import($catalogue);
        $sub = AttackTechnique::findByExternalId('T9001.001');
        $this->assertSame($id, $sub->id);
        $this->assertTrue((bool) $sub->is_deprecated);
        $this->assertSame('99.1', (new Query())->from('attack_release')->orderBy(['id' => SORT_DESC])->select('version')->scalar());
    }

    public function testBundledSnapshotIsLoaded(): void
    {
        $snapshot = CatalogueImporter::readSnapshot();
        $this->assertNotEmpty($snapshot['version']);
        $this->assertGreaterThan(10, count($snapshot['tactics']));
        $this->assertNotNull(AttackTechnique::findByExternalId('T1566.001'), 'migration should load the bundled catalogue');
        $this->assertNotNull(AttackMitigation::findByExternalId('M1032'));
    }

    public function testIdsMatchingIncludesSubTechniques(): void
    {
        $ids = AttackTechnique::idsMatching('t1566');
        $refs = AttackTechnique::find()->select('external_id')->where(['id' => $ids])->column();
        $this->assertContains('T1566', $refs);
        $this->assertContains('T1566.001', $refs);
        $this->assertNotContains('T1204', $refs);
        $this->assertSame([], AttackTechnique::idsMatching(' '));
        $this->assertSame([(int) AttackMitigation::findByExternalId('M1032')->id], AttackMitigation::idsMatching('m1032'));
    }

    public function testIncidentTechniqueLinksAreSyncedAndAudited(): void
    {
        $phish = AttackTechnique::findByExternalId('T1566.001');
        $exec = AttackTechnique::findByExternalId('T1204.002');
        $incident = new Incident([
            'title' => 'ATT&CK link test',
            'category_id' => $this->lookupId(Lookup::TYPE_INCIDENT_CATEGORY),
            'severity' => Incident::SEVERITY_HIGH,
            'status' => Incident::STATUS_NEW,
            'detected_at' => '2026-10-01 09:00:00',
            'techniqueIds' => [(string) $phish->id, '', $exec->id],
        ]);
        $this->assertTrue($incident->save(), json_encode($incident->getErrors()));
        $this->assertEqualsCanonicalizing(['T1204.002', 'T1566.001'], array_map(static fn($t) => $t->external_id, Incident::findOne($incident->id)->techniques));

        $incident = Incident::findOne($incident->id);
        $incident->techniqueIds = [$exec->id];
        $this->assertTrue($incident->save());
        $audit = AuditLog::find()->where(['entity_type' => 'incident', 'entity_id' => $incident->id, 'action' => 'update'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($audit);
        $this->assertSame([['T1204.002', 'T1566.001'], ['T1204.002']], array_map(static function ($v) {
            sort($v);
            return $v;
        }, $audit->getChangesArray()['techniqueIds']));

        // Unknown technique ids are rejected
        $incident->techniqueIds = [999999];
        $this->assertFalse($incident->validate(['techniqueIds']));
    }

    public function testMitigationCoverageFollowsDecisionsAndControls(): void
    {
        $technique = AttackTechnique::findByExternalId('T1566.002');
        $mfa = AttackMitigation::findByExternalId('M1017'); // User Training mitigates phishing
        $rows = Coverage::mitigationsFor([$technique->id], []);
        $this->assertArrayHasKey($mfa->id, $rows);
        $this->assertSame(Coverage::STATE_GAP, $rows[$mfa->id]['state']);
        // Gaps sort before covered mitigations; "Do Not Mitigate" is never listed
        $this->assertNotContains('M1055', array_map(static fn($r) => $r['mitigation']->external_id, $rows));

        $decision = new Decision([
            'title' => 'Security awareness programme',
            'category_id' => $this->lookupId(Lookup::TYPE_DECISION_CATEGORY),
            'decision_date' => '2026-10-01',
            'status' => Decision::STATUS_PROPOSED,
            'decision_makers' => 'CISO',
            'decision_text' => 'Train everyone.',
            'mitigationIds' => [$mfa->id],
        ]);
        $this->assertTrue($decision->save(), json_encode($decision->getErrors()));
        $this->assertSame(Coverage::STATE_PROPOSED, Coverage::mitigationsFor([$technique->id])[$mfa->id]['state']);

        // A decision only plans a mitigation, even once implemented: a control puts it in place
        $decision->status = Decision::STATUS_IMPLEMENTED;
        $this->assertTrue($decision->save(), json_encode($decision->getErrors()));
        $row = Coverage::mitigationsFor([$technique->id])[$mfa->id];
        $this->assertSame(Coverage::STATE_APPROVED, $row['state']);
        $this->assertSame($decision->id, $row['decisions'][0]->id);

        $control = new Control([
            'title' => 'Phishing awareness training',
            'status' => Control::STATUS_IMPLEMENTING,
            'control_type' => 'preventive',
            'nature' => 'administrative',
            'mitigationIds' => [$mfa->id],
        ]);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        $this->assertSame(Coverage::STATE_APPROVED, Coverage::mitigationsFor([$technique->id])[$mfa->id]['state'], 'implementing = planned');

        $control->status = Control::STATUS_IMPLEMENTED;
        $this->assertTrue($control->save());
        $row = Coverage::mitigationsFor([$technique->id])[$mfa->id];
        $this->assertSame(Coverage::STATE_IMPLEMENTED, $row['state']);
        $this->assertSame([$control->id], array_map(static fn($c) => $c->id, $row['controls']));

        // Tested ineffective: no longer in place
        $test = new ControlTest(['control_id' => $control->id, 'tested_on' => date('Y-m-d'), 'result' => Control::EFFECTIVENESS_INEFFECTIVE]);
        $this->assertTrue($test->save(), json_encode($test->getErrors()));
        $this->assertSame(Coverage::STATE_APPROVED, Coverage::mitigationsFor([$technique->id])[$mfa->id]['state']);

        $control->archive();
        $decision->archive();
        $this->assertSame(Coverage::STATE_GAP, Coverage::mitigationsFor([$technique->id])[$mfa->id]['state']);
    }

    public function testSubTechniquesInheritParentMitigations(): void
    {
        // A sub-technique whose parent has a mitigation the sub-technique itself is not mapped to
        $row = (new Query())->select(['s.id', 'mitigation' => 'm.external_id'])->from(['s' => 'attack_technique'])
            ->innerJoin(['pm' => 'attack_mitigation_technique'], 'pm.technique_id = s.parent_id')
            ->innerJoin(['m' => 'attack_mitigation'], 'm.id = pm.mitigation_id')
            ->leftJoin(['sm' => 'attack_mitigation_technique'], 'sm.technique_id = s.id AND sm.mitigation_id = pm.mitigation_id')
            ->where(['sm.mitigation_id' => null, 's.is_deprecated' => 0, 'm.is_deprecated' => 0])
            ->andWhere(['not in', 'm.external_id', Coverage::NOT_ACTIONABLE])
            ->one();
        $this->assertNotFalse($row, 'expected at least one parent-only mitigation in the catalogue');
        $sub = AttackTechnique::findOne($row['id']);
        $parentOnly = [$row['mitigation']];
        $listed = array_map(static fn($r) => $r['mitigation']->external_id, Coverage::mitigationsFor([$sub->id], []));
        foreach ($parentOnly as $id) {
            $this->assertContains($id, $listed);
        }
    }

    public function testDevicePlatforms(): void
    {
        $this->assertSame(['Windows'], DeviceAttack::platforms('laptop', 'Windows 11 Enterprise'));
        $this->assertSame(['macOS'], DeviceAttack::platforms('laptop', 'macOS'));
        $this->assertSame(['Linux'], DeviceAttack::platforms('server', 'Ubuntu Server'));
        $this->assertSame(['Linux', 'IaaS'], DeviceAttack::platforms('vm', 'Amazon Linux', 'Amazon'));
        $this->assertSame(['Network Devices'], DeviceAttack::platforms('firewall', 'FortiOS'));
        $this->assertSame([], DeviceAttack::platforms('mobile', 'iOS'));
        $this->assertSame([], DeviceAttack::platforms('printer', 'FutureSmart'));
        // The migrated device-check controls implement the mitigations the old posture columns mapped to
        $mapped = (new Query())->select('m.external_id')->from(['l' => 'control_attack_mitigation'])
            ->innerJoin(['m' => 'attack_mitigation'], 'm.id = l.mitigation_id')
            ->innerJoin(['c' => 'control'], 'c.id = l.control_id')->where(['not', ['c.device_check' => null]])->column();
        foreach (['M1041', 'M1049', 'M1040', 'M1028', 'M1033', 'M1037', 'M1051'] as $id) {
            $this->assertContains($id, $mapped);
        }
    }
}
