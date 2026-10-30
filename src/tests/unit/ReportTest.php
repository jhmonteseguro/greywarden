<?php

namespace tests\unit;

use app\components\Actor;
use app\components\RecordPdf;
use app\models\AuditLog;
use app\models\Lookup;
use app\models\Setting;
use app\models\User;
use app\modules\admin\models\ReportForm;
use app\modules\control\models\Control;
use app\modules\incident\components\IncidentMetrics;
use app\modules\report\components\History;
use app\modules\report\components\ReportBuilder;
use app\modules\report\components\ReportPeriod;
use app\modules\report\models\Report;
use app\modules\risk\models\Risk;
use tests\DbTestCase;
use Yii;
use yii\helpers\Json;
use yii\web\View;

/**
 * Management reports: periods, records rebuilt from the audit trail, the
 * figures for a known set of records over September 2026, and the report's
 * sign-off.
 */
class ReportTest extends DbTestCase
{
    // ---- Periods ----------------------------------------------------------------

    public function testPresetsAndNames(): void
    {
        $p = ReportPeriod::presets('2026-10-30');
        $this->assertSame(['month', '2026-09-01', '2026-09-30', 'Last month: September 2026'], [$p['last-month']['type'], $p['last-month']['start'], $p['last-month']['end'], $p['last-month']['label']]);
        $this->assertSame(['quarter', '2026-07-01', '2026-09-30', 'Last quarter: Q3 2026'], [$p['last-quarter']['type'], $p['last-quarter']['start'], $p['last-quarter']['end'], $p['last-quarter']['label']]);
        $this->assertSame('This month so far: October 2026 (to 30 Oct)', $p['this-month']['label']);
        $this->assertSame('This quarter so far: Q4 2026 (to 30 Oct)', $p['this-quarter']['label']);
        $this->assertSame(['2026-01-01', '2026-10-30', 'Year to date 2026 (to 30 Oct)'], [$p['year']['start'], $p['year']['end'], $p['year']['label']]);

        // Across a year end
        $jan = ReportPeriod::presets('2027-01-15');
        $this->assertSame(['2026-12-01', '2026-12-31'], [$jan['last-month']['start'], $jan['last-month']['end']]);
        $this->assertSame(['2026-10-01', '2026-12-31'], [$jan['last-quarter']['start'], $jan['last-quarter']['end']]);

        $this->assertSame('15 Aug – 14 Sep 2026', ReportPeriod::name(ReportPeriod::CUSTOM, '2026-08-15', '2026-09-14'));
        $this->assertSame('1 Dec 2025 – 15 Jan 2026', ReportPeriod::range('2025-12-01', '2026-01-15'));
        $this->assertSame('10 Sep – 30 Sep 2026', ReportPeriod::name(ReportPeriod::MONTH, '2026-09-10', '2026-09-30'), 'a month not from the 1st shows its dates');
        $this->assertSame('Security posture report · Q3 2026', ReportPeriod::title(ReportPeriod::QUARTER, '2026-07-01', '2026-09-30'));
        $this->assertTrue(ReportPeriod::isWhole(ReportPeriod::QUARTER, '2026-07-01', '2026-09-30'));
        $this->assertFalse(ReportPeriod::isWhole(ReportPeriod::QUARTER, '2026-08-01', '2026-10-31'));
        $this->assertFalse(ReportPeriod::isWhole(ReportPeriod::CUSTOM, '2026-09-01', '2026-09-30'));
        $this->assertSame(30, ReportPeriod::days('2026-09-01', '2026-09-30'));
        $this->assertSame([30, 90, 90, 30], array_map([ReportPeriod::class, 'lookaheadDays'], ['month', 'quarter', 'year', 'custom']));
    }

    public function testPreviousPeriod(): void
    {
        $cases = [
            [['month', '2026-09-01', '2026-09-30'], ['2026-08-01', '2026-08-31']],
            [['month', '2026-03-01', '2026-03-31'], ['2026-02-01', '2026-02-28']],
            [['month', '2026-03-01', '2026-03-31'], ['2026-02-01', '2026-02-28']],
            [['month', '2026-10-01', '2026-10-30'], ['2026-09-01', '2026-09-30']],   // in progress: to the same day, at most the month end
            [['month', '2026-03-01', '2026-03-15'], ['2026-02-01', '2026-02-15']],
            [['quarter', '2026-07-01', '2026-09-30'], ['2026-04-01', '2026-06-30']],
            [['quarter', '2026-10-01', '2026-10-30'], ['2026-07-01', '2026-07-30']],
            [['year', '2026-01-01', '2026-10-30'], ['2025-01-01', '2025-10-30']],
            [['custom', '2026-08-15', '2026-09-14'], ['2026-07-15', '2026-08-14']],
            [['month', '2026-09-10', '2026-09-30'], ['2026-08-20', '2026-09-09']],
        ];
        foreach ($cases as [$period, $expected]) {
            $this->assertSame($expected, ReportPeriod::previous(...$period), implode(' ', $period));
        }
    }

    // ---- Scenario: September 2026 -----------------------------------------------------

    private function at(string $when, ?string $entityType = null, ?int $id = null): void
    {
        $query = AuditLog::find()->orderBy(['id' => SORT_DESC]);
        if ($entityType !== null) {
            $query->where(['entity_type' => $entityType, 'entity_id' => $id]);
        }
        AuditLog::updateAll(['created_at' => $when], ['id' => $query->select('id')->scalar()]);
    }

    private function risk(string $created, array $attrs): Risk
    {
        $risk = new Risk($attrs + [
            'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY),
            'owner_id' => $this->userId(),
            'status' => Risk::STATUS_ASSESSED,
            'date_identified' => substr($created, 0, 10),
        ]);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        Risk::updateAll(['created_at' => $created], ['id' => $risk->id]);
        $this->at($created, 'risk', $risk->id);
        return $risk;
    }

    /** Changes a risk and dates its history entry */
    private function change(Risk $risk, string $when, array $attrs, bool $validate = true): void
    {
        $risk->setAttributes($attrs, false);
        $this->assertTrue($risk->save($validate), json_encode($risk->getErrors()));
        $this->at($when, 'risk', $risk->id);
    }

    private function insert(string $table, array $row): int
    {
        $db = Yii::$app->db;
        $db->createCommand()->insert($table, $row)->execute();
        return (int) $db->getLastInsertID();
    }

    /** Known records around September 2026; returns ids by name */
    private function scenario(): array
    {
        $db = Yii::$app->db;
        $db->createCommand()->delete('control')->execute();   // the migrations' device checks
        $ids = [];
        $a = $this->risk('2026-08-01 09:00:00', ['title' => 'Ransomware', 'inherent_likelihood' => 4, 'inherent_impact' => 5,
            'residual_likelihood' => 3, 'residual_impact' => 4, 'target_likelihood' => 2, 'target_impact' => 3, 'next_review_date' => '2026-09-15']);
        $this->change($a, '2026-09-10 10:00:00', ['residual_likelihood' => 4]);                              // rose 12 -> 16
        $this->change($a, '2026-10-10 10:00:00', ['residual_likelihood' => 1, 'residual_impact' => 4], false); // after the period: undone
        $b = $this->risk('2026-09-05 09:00:00', ['title' => 'Laptop theft', 'inherent_likelihood' => 2, 'inherent_impact' => 2, 'status' => Risk::STATUS_IDENTIFIED]);
        $c = $this->risk('2026-07-01 09:00:00', ['title' => 'Old VPN', 'inherent_likelihood' => 3, 'inherent_impact' => 3]);
        $this->change($c, '2026-09-20 10:00:00', ['status' => Risk::STATUS_CLOSED]);
        $d = $this->risk('2026-07-01 09:00:00', ['title' => 'Legacy payroll', 'inherent_likelihood' => 5, 'inherent_impact' => 5,
            'residual_likelihood' => 2, 'residual_impact' => 5, 'status' => Risk::STATUS_IN_TREATMENT, 'treatment' => Risk::TREATMENT_MITIGATE,
            'next_review_date' => '2026-10-20', 'target_treatment_date' => '2026-08-31']);
        $this->change($d, '2026-09-12 10:00:00', ['status' => Risk::STATUS_ACCEPTED, 'treatment' => Risk::TREATMENT_ACCEPT,
            'acceptance_justification' => 'Replaced next year', 'accepted_by_id' => $this->userId(), 'accepted_at' => '2026-09-12']);
        $this->risk('2026-10-05 09:00:00', ['title' => 'After the period', 'inherent_likelihood' => 5, 'inherent_impact' => 5]);
        $this->insert('record_review', ['entity_type' => 'risk', 'entity_id' => $a->id, 'reviewed_on' => '2026-09-14', 'reviewed_by_id' => $this->userId(),
            'outcome' => 'Raised likelihood', 'due_on' => '2026-09-01', 'next_review_date' => '2026-09-15', 'created_at' => '2026-09-14 10:00:00']);

        $incident = static fn(array $row) => $row + ['reference' => 'INC-' . substr(md5(json_encode($row)), 0, 6), 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00'];
        $ids['i1'] = $this->insert('incident', $incident(['title' => 'Phished mailbox', 'severity' => 'high', 'status' => 'closed', 'occurred_at' => '2026-09-02 12:00:00',
            'detected_at' => '2026-09-03 12:00:00', 'contained_at' => '2026-09-03 18:00:00', 'closed_at' => '2026-09-06 12:00:00', 'personal_data_involved' => 1,
            'regulator_notification_required' => 1, 'regulator_notified_at' => '2026-09-04 09:00:00', 'lessons_learned' => 'Patch faster', 'management_summary' => 'A mailbox was phished.']));
        $this->insert('incident', $incident(['title' => 'Outage', 'severity' => 'critical', 'status' => 'investigating', 'detected_at' => '2026-08-20 08:00:00']));
        $this->insert('incident', $incident(['title' => 'August malware', 'severity' => 'low', 'status' => 'closed', 'detected_at' => '2026-08-10 08:00:00', 'closed_at' => '2026-08-15 08:00:00']));
        $this->insert('incident', $incident(['title' => 'October', 'severity' => 'medium', 'status' => 'new', 'detected_at' => '2026-10-02 08:00:00']));
        $this->insert('incident', $incident(['title' => 'Archived', 'severity' => 'high', 'status' => 'new', 'detected_at' => '2026-09-02 08:00:00', 'archived_at' => '2026-09-03 08:00:00']));

        $control = static fn(array $row) => $row + ['control_type' => 'preventive', 'nature' => 'technical', 'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00'];
        $ids['k1'] = $this->insert('control', $control(['reference' => 'CTL-9001', 'title' => 'MFA', 'status' => 'implemented', 'effectiveness' => 'effective',
            'test_frequency_months' => 12, 'next_test_due' => '2026-09-20']));
        $ids['k2'] = $this->insert('control', $control(['reference' => 'CTL-9002', 'title' => 'Backups', 'status' => 'planned', 'effectiveness' => 'not_tested', 'target_date' => '2026-09-10']));
        $this->insert('control', $control(['reference' => 'CTL-9003', 'title' => 'Later', 'status' => 'implemented', 'effectiveness' => 'effective', 'created_at' => '2026-10-03 00:00:00']));
        AuditLog::record('control', $ids['k2'], 'CTL-9002 · Backups', 'update', ['status' => ['planned', 'implementing']]);
        $this->at('2026-09-25 10:00:00');
        $db->createCommand()->update('control', ['status' => 'implementing'], ['id' => $ids['k2']])->execute();
        $this->insert('control_test', ['control_id' => $ids['k1'], 'tested_on' => '2026-09-05', 'result' => 'effective', 'created_at' => '2026-09-05 00:00:00']);
        $this->insert('control_test', ['control_id' => $ids['k1'], 'tested_on' => '2026-08-05', 'result' => 'partial', 'created_at' => '2026-08-05 00:00:00']);
        $this->insert('incident_control', ['incident_id' => $ids['i1'], 'control_id' => $ids['k1'], 'role' => 'failed', 'created_at' => '2026-09-06 00:00:00']);

        $this->insert('decision', ['reference' => 'DEC-9001', 'title' => 'Buy EDR', 'decision_date' => '2026-09-08', 'status' => 'approved', 'review_date' => '2026-09-30',
            'decision_text' => 'Roll out EDR', 'created_at' => '2026-09-08 00:00:00', 'updated_at' => '2026-09-08 00:00:00']);
        return $ids + ['a' => $a->id, 'b' => $b->id, 'c' => $c->id, 'd' => $d->id];
    }

    public function testHistoryRebuildsRecordsAtAMoment(): void
    {
        $ids = $this->scenario();
        $august = History::asAt('risk', 'risk', '2026-08-31 23:59:59');
        $this->assertArrayNotHasKey($ids['b'], $august, 'created later');
        $this->assertSame([3, 'assessed'], [(int) $august[$ids['a']]['residual_likelihood'], $august[$ids['c']]['status']]);
        $september = History::asAt('risk', 'risk', '2026-09-30 23:59:59');
        $this->assertSame([4, 4, 'closed'], [(int) $september[$ids['a']]['residual_likelihood'], (int) $september[$ids['a']]['residual_impact'], $september[$ids['c']]['status']]);
        $this->assertSame(1, (int) Risk::findOne($ids['a'])->residual_likelihood, 'the record itself is unchanged');
        $this->assertSame([], History::asAt('risk', 'risk', '2020-01-01 00:00:00'));

        // Unreadable or foreign entries are skipped
        AuditLog::updateAll(['changes' => '{not json'], ['entity_type' => 'risk', 'entity_id' => $ids['c'], 'action' => 'update']);
        AuditLog::record('risk', 999999, 'gone', 'update', ['status' => ['identified', 'closed']]);
        AuditLog::record('risk', $ids['a'], 'x', 'update', ['not_a_column' => [1, 2], 'title' => 'not a pair']);
        $this->assertSame('closed', History::asAt('risk', 'risk', '2026-08-31 23:59:59')[$ids['c']]['status']);
    }

    public function testFiguresForSeptember(): void
    {
        $ids = $this->scenario();
        $d = (new ReportBuilder(ReportPeriod::MONTH, '2026-09-01', '2026-09-30'))->build();

        $this->assertSame(ReportBuilder::VERSION, $d['version']);
        $this->assertSame(['2026-08-01', '2026-08-31', 30, 'September 2026'],
            [$d['period']['previous_start'], $d['period']['previous_end'], $d['period']['lookahead_days'], $d['period']['name']]);
        $this->assertSame([9, 'Medium', null], [$d['appetite'], $d['appetite_rating'], $d['previous_report']]);

        // Risks: start and end as they stood, changes in between
        $r = $d['risks'];
        $this->assertSame(['open' => 3, 'above_appetite' => 2, 'high_critical' => 2, 'above_target' => 1, 'review_overdue' => 0, 'treatment_overdue' => 1],
            array_diff_key($r['start'], ['ratings' => 1]));
        $this->assertSame(['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 0], $r['start']['ratings']);
        $this->assertSame(['open' => 3, 'above_appetite' => 2, 'high_critical' => 2, 'above_target' => 1, 'review_overdue' => 1, 'treatment_overdue' => 0],
            array_diff_key($r['end'], ['ratings' => 1]));
        $this->assertSame(['low' => 1, 'medium' => 0, 'high' => 2, 'critical' => 0], $r['end']['ratings']);
        $this->assertSame(['Laptop theft'], array_column($r['added'], 'title'));
        $this->assertSame([['Old VPN', 'Closed']], array_map(static fn($x) => [$x['title'], $x['how']], $r['closed']));
        $this->assertSame([['Ransomware', 12, 16, 'high']], array_map(static fn($x) => [$x['title'], $x['from'], $x['score'], $x['rating']], $r['rose']));
        $this->assertSame([], $r['fell']);
        $this->assertSame(['Ransomware', 'Legacy payroll', 'Laptop theft'], array_column($r['top'], 'title'));
        $this->assertSame([20, 16, 6, User::findOne($this->userId())->full_name], [$r['top'][0]['inherent'], $r['top'][0]['residual'], $r['top'][0]['target'], $r['top'][0]['owner']]);
        $this->assertSame(['Ransomware', 'Legacy payroll'], array_column($r['above_appetite'], 'title'));
        $this->assertSame([['Legacy payroll', 'Replaced next year', '2026-09-12']], array_map(static fn($x) => [$x['title'], $x['justification'], $x['accepted_at']], $r['accepted']));
        $this->assertSame([4 => [4 => 1], 2 => [2 => 1, 5 => 1]], $r['heatmap']);
        $this->assertSame(['count' => 1, 'late' => 1], array_diff_key($r['reviews'], ['list' => 1]));
        $this->assertSame([['Ransomware', '2026-09-15']], array_map(static fn($x) => [$x['title'], $x['due']], $r['review_overdue']));
        $this->assertSame([], $r['treatment_overdue'], 'accepted risks are no longer in treatment');

        // Incidents by their own dates; archived ones left out
        $i = $d['incidents'];
        $this->assertSame([1, 1, 1, 1, 2, 1], [$i['opened'], $i['closed'], $i['open_end'], $i['open_start'], $i['opened_previous'], $i['closed_previous']]);
        $this->assertSame(['opened' => 1, 'closed' => 1, 'open_end' => 0], $i['by_severity']['high']);
        $this->assertSame(1, $i['by_severity']['critical']['open_end']);
        $this->assertSame([86400, 21600, 259200, 1], array_values($i['times']));
        $this->assertSame([1, 1, 1], [$i['personal_data'], $i['regulator']['required'], $i['regulator'][IncidentMetrics::STATE_MET]]);
        $this->assertSame(['Outage', 'Phished mailbox'], array_column($i['list'], 'title'), 'most severe first');
        $this->assertSame([true, 'investigating'], [$i['list'][0]['open_end'], $i['list'][0]['status']]);
        $this->assertSame(['A mailbox was phished.', 'closed'], [$i['list'][1]['summary'], $i['list'][1]['status']]);
        $this->assertSame([['Patch faster']], array_map(static fn($x) => [$x['text']], $i['lessons']));
        $this->assertSame(['CTL-9001'], array_column($i['failed_controls'], 'ref'));

        // Controls: status rebuilt from the history, tests by date
        $c = $d['controls'];
        $this->assertSame([1, 1, 100, 0, 0], [$c['start']['in_force'], $c['start']['effective'], $c['start']['effective_pct'], $c['start']['test_overdue'], $c['start']['target_overdue']]);
        $this->assertSame([2, 1, 50, 1, 1, 1], [$c['end']['in_force'], $c['end']['effective'], $c['end']['effective_pct'], $c['end']['test_overdue'], $c['end']['target_overdue'], $c['end']['not_tested']]);
        $this->assertSame([['CTL-9002', 'Planned', 'Implementing']], array_map(static fn($x) => [$x['ref'], $x['from'], $x['to']], $c['changes']));
        $this->assertSame([1, 1, ['effective' => 1, 'partial' => 0, 'ineffective' => 0]], [$c['tests']['count'], $c['tests']['previous'], $c['tests']['results']]);
        $this->assertSame(['CTL-9001'], array_column($c['test_overdue'], 'ref'));
        $this->assertSame(['CTL-9002'], array_column($c['target_overdue'], 'ref'));
        $this->assertArrayHasKey('iso27001', $c['frameworks']);
        $this->assertNull($c['frameworks']['iso27001']['previous']);

        // Decisions
        $this->assertSame([1, 1, 0, 'Roll out EDR'], [$d['decisions']['made_count'], $d['decisions']['review_overdue_count'], $d['decisions']['review_overdue_start'], $d['decisions']['made'][0]['decision']]);

        // Nothing to report on devices, cloud, edge or suppliers
        $this->assertSame(['devices' => null, 'cloud' => null, 'edge' => null], $d['assets']);
        $this->assertNull($d['suppliers']);

        // Looking ahead uses the records as they are now
        $this->assertSame([['Risk review', 'Legacy payroll', '2026-10-20']], array_map(static fn($x) => [$x['kind'], $x['title'], $x['due']], $d['ahead']['list']));

        // Activity: the dated history entries in September
        $this->assertSame(['risk' => 4, 'control' => 1], $d['activity']['by_type']);

        // Headline and overall status
        $this->assertSame(['above_appetite', 'high_critical', 'incidents_opened', 'incidents_open', 'control_effectiveness', 'overdue_reviews', 'overdue_tests'], array_column($d['headline'], 'key'));
        $this->assertSame([2, 0], [$d['headline'][5]['value'], $d['headline'][5]['compare']]);
        $this->assertSame([50, 100], [$d['headline'][4]['value'], $d['headline'][4]['compare']]);
        $this->assertSame(ReportBuilder::RAG_RED, $d['suggested_rag']);
        $this->assertSame(['1 critical or high incident still open', '2 risks above appetite', '1 risk rose', '2 overdue reviews', '1 overdue control test'], $d['rag_reasons']);

        // A lower appetite: the comparisons follow it
        Setting::put([ReportBuilder::SETTING_APPETITE => '16']);
        $d = (new ReportBuilder(ReportPeriod::MONTH, '2026-09-01', '2026-09-30'))->build();
        $this->assertSame([16, 0], [$d['appetite'], $d['risks']['end']['above_appetite']]);
    }

    public function testTreatedRisksAreResidualAcceptances(): void
    {
        $risk = $this->risk('2026-07-01 09:00:00', ['title' => 'Flat network', 'inherent_likelihood' => 4, 'inherent_impact' => 4,
            'residual_likelihood' => 3, 'residual_impact' => 4, 'status' => Risk::STATUS_IN_TREATMENT, 'treatment' => Risk::TREATMENT_MITIGATE,
            'target_treatment_date' => '2026-09-30']);
        $this->change($risk, '2026-09-18 10:00:00', ['status' => Risk::STATUS_TREATED, 'residual_likelihood' => 1,
            'acceptance_justification' => 'Segmented; within appetite', 'accepted_by_id' => $this->userId(), 'accepted_at' => '2026-09-18']);
        $this->risk('2026-07-01 09:00:00', ['title' => 'Treated before', 'inherent_likelihood' => 3, 'inherent_impact' => 3,
            'residual_likelihood' => 1, 'residual_impact' => 3, 'status' => Risk::STATUS_TREATED, 'treatment' => Risk::TREATMENT_AVOID,
            'acceptance_justification' => 'Old', 'accepted_by_id' => $this->userId(), 'accepted_at' => '2026-07-01']);

        $r = (new ReportBuilder(ReportPeriod::MONTH, '2026-09-01', '2026-09-30'))->build()['risks'];
        $this->assertSame([['Flat network', 'Treated', 'Mitigate', 'Segmented; within appetite']],
            array_map(static fn($x) => [$x['title'], $x['status'], $x['treatment'], $x['justification']], $r['accepted']),
            'only acceptances made in the period are listed');
        $this->assertSame(2, $r['end']['open'], 'treated risks stay open');
        $this->assertSame([], $r['treatment_overdue'], 'treated risks have no pending treatment');

        $report = $this->report();
        $html = RecordPdf::html(new View(), Yii::getAlias('@app/modules/report/views/report/pdf.php'), $report, '1 Oct 2026 09:00', 'Test User');
        $this->assertStringContainsString('Treated · Mitigate', $html);
    }

    public function testOverallStatusSuggestions(): void
    {
        Yii::$app->db->createCommand()->delete('control')->execute();
        $d = (new ReportBuilder(ReportPeriod::MONTH, '2026-09-01', '2026-09-30'))->build();
        $this->assertSame([ReportBuilder::RAG_GREEN, []], [$d['suggested_rag'], $d['rag_reasons']]);
        $this->assertNull($d['controls']['end']['effective_pct']);

        $this->risk('2026-08-01 09:00:00', ['title' => 'Medium-high', 'inherent_likelihood' => 2, 'inherent_impact' => 5]);
        $d = (new ReportBuilder(ReportPeriod::MONTH, '2026-09-01', '2026-09-30'))->build();
        $this->assertSame([ReportBuilder::RAG_AMBER, ['1 risk above appetite']], [$d['suggested_rag'], $d['rag_reasons']]);

        $this->risk('2026-08-01 09:00:00', ['title' => 'Critical', 'inherent_likelihood' => 5, 'inherent_impact' => 5]);
        $this->insert('incident', ['reference' => 'INC-9100', 'title' => 'Late notice', 'severity' => 'low', 'status' => 'closed', 'detected_at' => '2026-09-02 08:00:00',
            'closed_at' => '2026-09-20 08:00:00', 'regulator_notification_required' => 1, 'regulator_notified_at' => '2026-09-10 08:00:00',
            'created_at' => '2026-09-02 08:00:00', 'updated_at' => '2026-09-02 08:00:00']);
        $this->insert('incident', ['reference' => 'INC-9101', 'title' => 'Open low', 'severity' => 'low', 'status' => 'new', 'detected_at' => '2026-09-29 08:00:00',
            'regulator_notification_required' => 1, 'created_at' => '2026-09-25 08:00:00', 'updated_at' => '2026-09-25 08:00:00']);
        $d = (new ReportBuilder(ReportPeriod::MONTH, '2026-09-01', '2026-09-30'))->build();
        $this->assertSame(ReportBuilder::RAG_RED, $d['suggested_rag']);
        $this->assertSame(['1 critical risk above appetite', '1 regulator notification late or overdue', '1 risk above appetite', '1 other incident still open'], $d['rag_reasons']);
        $this->assertSame([1, 0, 1], [$d['incidents']['regulator'][IncidentMetrics::STATE_LATE], $d['incidents']['regulator'][IncidentMetrics::STATE_OVERDUE],
            $d['incidents']['regulator'][IncidentMetrics::STATE_PENDING]]);
        $this->assertSame([null, null, 18 * 86400, 1], array_values($d['incidents']['times']));
    }

    // ---- The report ---------------------------------------------------------------------

    private function report(array $attrs = []): Report
    {
        $report = new Report(['scenario' => Report::SCENARIO_CREATE] + $attrs + ['preset' => ReportPeriod::CUSTOM, 'period_type' => ReportPeriod::MONTH,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30']);
        $this->assertTrue($report->create(), json_encode($report->getErrors()));
        return $report;
    }

    public function testCreateApproveAndWithdraw(): void
    {
        Actor::override($this->userId(), 'tester');
        $report = $this->report();
        $this->assertMatchesRegularExpression('/^RPT-\d{4}$/', $report->reference);
        $this->assertSame(['Security posture report · September 2026', Report::STATUS_DRAFT, $report->getFigures()['suggested_rag'], ReportBuilder::VERSION],
            [$report->title, $report->status, $report->rag, $report->data_version]);
        $this->assertSame($report->getFigures(), Json::decode(Report::findOne($report->id)->data));
        $this->assertSame('September 2026', $report->getPeriodName());
        $this->assertSame(['Draft', null], [$report->getStatusLabel(), (new Report())->getRagLabel()]);
        $this->assertSame(['Write the management summary.'], $report->approvalBlockers());
        $this->assertFalse($report->approve());
        $this->assertArrayNotHasKey('data', AuditLog::find()->where(['entity_type' => 'report', 'entity_id' => $report->id, 'action' => 'create'])->one()->getChangesArray(),
            'the frozen figures stay out of the history');

        $report->scenario = Report::SCENARIO_DEFAULT;
        $report->rag = null;
        $report->management_summary = 'All fine.';
        $this->assertTrue($report->save());
        $this->assertSame(['Set the overall status.'], $report->approvalBlockers());
        $report->rag = 'green';
        $this->assertTrue($report->save());
        $this->assertSame('Green', $report->getRagLabel());
        $this->assertTrue($report->approve(str_repeat('n', Report::NOTE_MAX + 10)));
        $this->assertTrue($report->isApproved());
        $this->assertSame(Report::NOTE_MAX, mb_strlen($report->approval_note));
        $this->assertSame($this->userId(), (int) $report->approvedBy->id);
        $this->assertFalse($report->regenerate(), 'approved reports keep their figures');

        $this->assertTrue($report->withdrawApproval());
        $this->assertTrue($report->isDraft());
        $this->assertFalse($report->withdrawApproval());
        $this->assertTrue($report->approve(''));
        $this->assertNull($report->approval_note);
        $actions = AuditLog::find()->select('action')->where(['entity_type' => 'report', 'entity_id' => $report->id])->orderBy('id')->column();
        $this->assertSame(['create', 'update', 'update', 'approve', 'approval_withdraw', 'approve'], $actions);

        $report->archive();
        $this->assertFalse($report->withdrawApproval());
        Actor::override(null);
    }

    public function testValidationAndPreviousReport(): void
    {
        $bad = new Report(['scenario' => Report::SCENARIO_CREATE, 'preset' => ReportPeriod::CUSTOM, 'period_type' => 'week', 'period_start' => '2026-09-01', 'period_end' => '2030-01-01']);
        $this->assertFalse($bad->create());
        $this->assertArrayHasKey('period_type', $bad->getErrors());
        $this->assertStringContainsString('future', $bad->getFirstError('period_end'));
        $this->assertNull($bad->id);

        $quarter = $this->report(['period_type' => ReportPeriod::QUARTER, 'period_start' => '2026-04-01', 'period_end' => '2026-06-30']);
        $august = $this->report(['period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'title' => 'August']);
        $september = $this->report(['period_start' => '2026-09-01', 'period_end' => '2026-09-30']);
        $this->assertSame($august->id, $september->findPrevious()->id, 'the same kind of period first');
        $this->assertSame($august->reference, $september->getFigures()['previous_report']);
        $this->assertSame('August', $august->title);
        $q3 = $this->report(['period_type' => ReportPeriod::QUARTER, 'period_start' => '2026-07-01', 'period_end' => '2026-09-30']);
        $this->assertSame($quarter->id, $q3->findPrevious()->id);
        $this->assertNull($quarter->findPrevious());
        $july = $this->report(['period_start' => '2026-07-01', 'period_end' => '2026-07-31']);
        $this->assertSame($quarter->id, $july->findPrevious()->id, 'any earlier report when none of its kind');

        // Figures from an unknown version cannot be shown or approved
        Report::updateAll(['data' => 'not json'], ['id' => $july->id]);
        $july = Report::findOne($july->id);
        $this->assertSame([], $july->getFigures());
        $this->assertContains('Regenerate the figures.', $july->approvalBlockers());
    }

    public function testAppetiteSettingForm(): void
    {
        $this->assertSame(ReportBuilder::DEFAULT_APPETITE, ReportForm::current()->risk_appetite);
        $this->assertSame('9 · Medium', ReportForm::appetiteOptions()[9]);
        $this->assertArrayNotHasKey(7, ReportForm::appetiteOptions(), '7 is not a possible score');
        $this->assertStringContainsString('Medium risks and below', (new ReportForm())->attributeHints()['risk_appetite']);

        $form = new ReportForm(['risk_appetite' => '0']);
        $this->assertFalse($form->save());
        $form = new ReportForm(['risk_appetite' => '15']);
        $this->assertTrue($form->save());
        $this->assertSame(15, ReportBuilder::appetite());
        $count = (int) AuditLog::find()->where(['entity_type' => 'setting'])->count();
        $this->assertTrue((new ReportForm(['risk_appetite' => '15']))->save());
        $this->assertSame($count, (int) AuditLog::find()->where(['entity_type' => 'setting'])->count(), 'no change, nothing logged');
        Setting::put([ReportBuilder::SETTING_APPETITE => 'junk']);
        $this->assertSame(ReportBuilder::DEFAULT_APPETITE, ReportBuilder::appetite());
    }

    public function testPdfDocument(): void
    {
        $report = $this->report();
        $report->scenario = Report::SCENARIO_DEFAULT;
        $report->management_summary = "Line one <b>\nLine two";
        $report->rag = ReportBuilder::RAG_GREEN;
        $report->save();
        $html = RecordPdf::html(new View(), Yii::getAlias('@app/modules/report/views/report/pdf.php'), $report, '1 Oct 2026 09:00', 'Test User');
        $this->assertStringContainsString('Management report · September 2026', $html);
        $this->assertStringContainsString('<strong>Draft</strong>', $html);
        $this->assertStringContainsString("Line one &lt;b&gt;<br />\nLine two", $html);
        $this->assertStringContainsString('class="rag-box rag-box--green"', $html);
        $this->assertStringContainsString('Draft: not approved yet.', $html);
        $this->assertStringNotContainsString('href="/search', $html, 'plain references in print');
        $this->assertStringStartsWith('%PDF-', RecordPdf::render(new View(), Yii::getAlias('@app/modules/report/views/report/pdf.php'), $report));
        $this->assertSame($report->reference . '-security-posture-report-september-2026.pdf', RecordPdf::filename($report));
    }
}
