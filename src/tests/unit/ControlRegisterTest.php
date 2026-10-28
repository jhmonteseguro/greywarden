<?php

namespace tests\unit;

use app\models\AuditLog;
use app\models\Lookup;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\components\FrameworkCoverage;
use app\modules\control\models\Control;
use app\modules\control\models\ControlTest;
use app\modules\control\models\FrameworkRequirement;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use tests\DbTestCase;
use Yii;

class ControlRegisterTest extends DbTestCase
{
    private function makeControl(array $attrs = []): Control
    {
        $control = new Control($attrs + [
            'title' => 'MFA on cloud services',
            'status' => Control::STATUS_PLANNED,
            'control_type' => 'preventive',
            'nature' => 'technical',
        ]);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        return $control;
    }

    private function recordTest(Control $control, string $date, string $result): ControlTest
    {
        $test = new ControlTest(['control_id' => $control->id, 'tested_on' => $date, 'result' => $result]);
        $this->assertTrue($test->save(), json_encode($test->getErrors()));
        $control->refresh();
        return $test;
    }

    private function requirement(string $externalId): FrameworkRequirement
    {
        $r = FrameworkRequirement::findOne(['external_id' => $externalId]);
        $this->assertNotNull($r, "$externalId is not in the catalogue");
        return $r;
    }

    public function testCataloguesAreLoadedAndReimportKeepsApplicability(): void
    {
        $this->assertSame(93, (int) FrameworkRequirement::find()->where(['framework' => FrameworkCatalogue::ISO27001])->count(), 'ISO 27001:2022 Annex A has 93 controls');
        $this->assertSame(27, (int) FrameworkRequirement::find()->where(['framework' => FrameworkCatalogue::CYBER_ESSENTIALS])->count());
        $this->assertSame('Technological controls', $this->requirement('A.8.7')->group_name);
        $this->assertSame('User access control', $this->requirement('CE-UA-4')->group_name);

        $r = $this->requirement('A.7.4');
        $r->applicable = false;
        $this->assertFalse($r->validate(), 'excluding a requirement needs a justification');
        $r->justification = 'No premises: fully remote organisation.';
        $this->assertTrue($r->save(), json_encode($r->getErrors()));

        FrameworkCatalogue::import(Yii::$app->db);
        $r->refresh();
        $this->assertFalse((bool) $r->applicable);
        $this->assertSame('No premises: fully remote organisation.', $r->justification);
        $this->assertSame(93, (int) FrameworkRequirement::find()->where(['framework' => FrameworkCatalogue::ISO27001])->count(), 'import is idempotent');
        $this->assertNotNull(AuditLog::findOne(['entity_type' => 'requirement', 'entity_id' => $r->id]));
    }

    public function testEffectivenessAndScheduleComeFromTests(): void
    {
        $control = $this->makeControl(['status' => Control::STATUS_IMPLEMENTED, 'implemented_on' => '2026-01-10', 'test_frequency_months' => 3]);
        $this->assertSame(Control::EFFECTIVENESS_NOT_TESTED, $control->effectiveness);
        $this->assertSame('2026-01-10', $control->next_test_due, 'an untested control is due from the day it was implemented');
        $this->assertTrue($control->isTestOverdue('2026-02-01'));

        $this->recordTest($control, '2026-03-01', Control::EFFECTIVENESS_EFFECTIVE);
        $this->assertSame('2026-03-01', $control->last_tested_on);
        $this->assertSame(Control::EFFECTIVENESS_EFFECTIVE, $control->effectiveness);
        $this->assertSame('2026-06-01', $control->next_test_due);

        // The latest test by date wins, whatever order tests are entered in
        $older = $this->recordTest($control, '2026-02-01', Control::EFFECTIVENESS_INEFFECTIVE);
        $this->assertSame(Control::EFFECTIVENESS_EFFECTIVE, $control->effectiveness);
        $latest = $this->recordTest($control, '2026-05-15', Control::EFFECTIVENESS_PARTIAL);
        $this->assertSame(Control::EFFECTIVENESS_PARTIAL, $control->effectiveness);
        $this->assertSame('2026-08-15', $control->next_test_due);

        $latest->delete();
        $control->refresh();
        $this->assertSame(Control::EFFECTIVENESS_EFFECTIVE, $control->effectiveness);
        $this->assertSame('2026-03-01', $control->last_tested_on);

        // Effectiveness changes are in the control's own history
        $changes = array_map(static fn(AuditLog $l) => $l->getChangesArray(), AuditLog::find()
            ->where(['entity_type' => 'control', 'entity_id' => $control->id, 'action' => 'update'])->all());
        $this->assertNotEmpty(array_filter($changes, static fn($c) => isset($c['effectiveness'])));

        // No schedule, or not implemented: nothing is due
        $control->test_frequency_months = null;
        $this->assertTrue($control->save());
        $this->assertNull($control->next_test_due);
        $older->delete();

        $future = new ControlTest(['control_id' => $control->id, 'tested_on' => date('Y-m-d', strtotime('+2 days')), 'result' => 'effective']);
        $this->assertFalse($future->validate());
    }

    public function testOverdueQueriesMatchPhpLogic(): void
    {
        $today = '2026-06-30';
        $controls = [
            'due' => $this->makeControl(['status' => Control::STATUS_IMPLEMENTED, 'implemented_on' => '2026-01-01', 'test_frequency_months' => 1]),
            'scheduled' => $this->makeControl(['status' => Control::STATUS_IMPLEMENTED, 'implemented_on' => '2026-01-01', 'test_frequency_months' => 12]),
            'late' => $this->makeControl(['status' => Control::STATUS_PLANNED, 'target_date' => '2026-06-01']),
            'ontime' => $this->makeControl(['status' => Control::STATUS_IMPLEMENTING, 'target_date' => '2026-12-01']),
        ];
        $this->recordTest($controls['scheduled'], '2026-05-01', Control::EFFECTIVENESS_EFFECTIVE);
        $ids = array_map(static fn(Control $c) => $c->id, $controls);
        $testOverdue = array_map('intval', Control::find()->where(['control.id' => $ids])->testOverdue($today)->select('control.id')->column());
        $targetOverdue = array_map('intval', Control::find()->where(['control.id' => $ids])->targetOverdue($today)->select('control.id')->column());
        $this->assertSame([$ids['due']], $testOverdue);
        $this->assertSame([$ids['late']], $targetOverdue);
        foreach ($controls as $name => $c) {
            $this->assertSame(in_array($c->id, $testOverdue, true), $c->isTestOverdue($today), $name);
            $this->assertSame(in_array($c->id, $targetOverdue, true), $c->isTargetOverdue($today), $name);
        }
    }

    public function testDueSoonWindowMatchesSql(): void
    {
        $today = '2026-06-30';
        $this->assertSame(14, Control::testDueSoonDays());
        // next_test_due = last test + 1 month; tested on these dates
        $cases = [
            'overdue' => ['2026-05-29', false, true],      // due 2026-06-29
            'today' => ['2026-05-30', true, false],        // due 2026-06-30
            'edge' => ['2026-06-14', true, false],         // due 2026-07-14 = today + 14
            'later' => ['2026-06-15', false, false],       // due 2026-07-15
        ];
        $controls = [];
        foreach ($cases as $name => [$tested]) {
            $controls[$name] = $this->makeControl(['title' => $name, 'status' => Control::STATUS_IMPLEMENTED, 'implemented_on' => '2026-01-01', 'test_frequency_months' => 1]);
            $this->recordTest($controls[$name], $tested, Control::EFFECTIVENESS_EFFECTIVE);
        }
        $ids = array_map(static fn(Control $c) => $c->id, $controls);
        $soon = array_map('intval', Control::find()->where(['control.id' => $ids])->testDueSoon($today)->select('control.id')->column());
        $this->assertEqualsCanonicalizing([$ids['today'], $ids['edge']], $soon);
        foreach ($cases as $name => [, $dueSoon, $overdue]) {
            $this->assertSame($dueSoon, $controls[$name]->isTestDueSoon($today), $name);
            $this->assertSame($overdue, $controls[$name]->isTestOverdue($today), $name);
        }
        $this->assertSame(0, $controls['today']->getDaysUntilTest($today));
        $this->assertSame(-1, $controls['overdue']->getDaysUntilTest($today));
        $this->assertStringContainsString('Test due today', $controls['today']->testFlag($today));
        $this->assertStringContainsString('Test due in 14d', $controls['edge']->testFlag($today));
        $this->assertStringContainsString('Test overdue', $controls['overdue']->testFlag($today));
        $this->assertSame('', $controls['later']->testFlag($today));
        $this->assertSame([], $controls['edge']->weaknesses($today), 'due soon is a reminder, not a weakness');
    }

    public function testRisksAreFlaggedWhenTheirControlsAreWeak(): void
    {
        $risk = new Risk([
            'title' => 'Account takeover',
            'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY),
            'owner_id' => $this->userId(),
            'inherent_likelihood' => 4,
            'inherent_impact' => 4,
            'residual_likelihood' => 2,
            'residual_impact' => 4,
            'date_identified' => '2026-06-01',
            'status' => Risk::STATUS_ASSESSED,
        ]);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        $control = $this->makeControl(['status' => Control::STATUS_IMPLEMENTED, 'riskIds' => [$risk->id]]);
        $this->assertSame([$control->id], array_map(static fn($c) => $c->id, Risk::findOne($risk->id)->controls), 'linked from the control side');

        $issues = static fn() => array_map('intval', Risk::find()->where(['risk.id' => $risk->id])->controlIssues()->select('risk.id')->column());
        $this->assertSame([], Risk::findOne($risk->id)->getControlIssues());
        $this->assertSame([], $issues());

        $this->recordTest($control, date('Y-m-d'), Control::EFFECTIVENESS_INEFFECTIVE);
        $found = Risk::findOne($risk->id)->getControlIssues();
        $this->assertSame(['ineffective'], $found[$control->id]['reasons']);
        $this->assertSame([$risk->id], $issues());

        // Linked from the risk side too; a retired control is also an issue
        $sound = $this->makeControl(['title' => 'Conditional access', 'status' => Control::STATUS_IMPLEMENTED]);
        $risk->controlIds = [$sound->id];
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        $this->assertSame([], $issues());
        $sound->status = Control::STATUS_RETIRED;
        $this->assertTrue($sound->save());
        $this->assertSame([$risk->id], $issues());
        $this->assertSame(['retired'], Risk::findOne($risk->id)->getControlIssues()[$sound->id]['reasons']);

        // Closed risks are not flagged
        $risk->status = Risk::STATUS_CLOSED;
        $this->assertTrue($risk->save(false));
        $this->assertSame([], $issues());
    }

    public function testFrameworkRollUp(): void
    {
        $req = $this->requirement('CE-UA-4');
        $state = static fn() => FrameworkCoverage::rows(FrameworkCatalogue::CYBER_ESSENTIALS, [$req->id])[$req->id];
        $this->assertSame(FrameworkCoverage::STATE_GAP, $state()['state']);

        $control = $this->makeControl(['requirementIds' => [$req->id, $this->requirement('A.8.5')->id]]);
        $this->assertSame(['iso27001' => ['A.8.5'], 'cyber_essentials' => ['CE-UA-4']], Control::findOne($control->id)->frameworkSummary());
        $this->assertSame(FrameworkCoverage::STATE_PLANNED, $state()['state']);

        $control->status = Control::STATUS_IMPLEMENTING;
        $this->assertTrue($control->save());
        $this->assertSame(FrameworkCoverage::STATE_PARTIAL, $state()['state']);

        $control->status = Control::STATUS_IMPLEMENTED;
        $this->assertTrue($control->save());
        $this->assertSame(FrameworkCoverage::STATE_MET, $state()['state']);
        $this->assertTrue($state()['unverified'], 'met by an untested control');

        $this->recordTest($control, date('Y-m-d'), Control::EFFECTIVENESS_EFFECTIVE);
        $this->assertFalse($state()['unverified']);
        $this->recordTest($control, date('Y-m-d'), Control::EFFECTIVENESS_INEFFECTIVE);
        $this->assertSame(FrameworkCoverage::STATE_PARTIAL, $state()['state']);

        // The best linked control counts; retired / archived ones do not
        $second = $this->makeControl(['title' => 'Hardware keys for admins', 'status' => Control::STATUS_IMPLEMENTED, 'requirementIds' => [$req->id]]);
        $this->assertSame(FrameworkCoverage::STATE_MET, $state()['state']);

        // Controls that cover only part of the requirement cap it at partly met
        $req->partial_coverage = true;
        $this->assertFalse($req->save(), 'a coverage gap needs describing');
        $req->coverage_gap = 'Google accounts only';
        $this->assertTrue($req->save());
        $this->assertSame(FrameworkCoverage::STATE_PARTIAL, $state()['state']);
        $req->partial_coverage = false;
        $this->assertTrue($req->save());

        $second->archive();
        $this->assertSame(FrameworkCoverage::STATE_PARTIAL, $state()['state']);

        $req->applicable = false;
        $req->justification = 'Test exclusion';
        $this->assertTrue($req->save());
        $this->assertSame(FrameworkCoverage::STATE_EXCLUDED, $state()['state']);

        $summary = FrameworkCoverage::summary(FrameworkCoverage::rows(FrameworkCatalogue::CYBER_ESSENTIALS));
        $this->assertSame(27, $summary['total']);
        $this->assertSame(26, $summary['applicable']);
        $this->assertSame($summary['applicable'], $summary['met'] + $summary['partial'] + $summary['planned'] + $summary['gap']);
    }

    public function testIncidentControlRolesAreSeparateLinks(): void
    {
        $incident = new Incident([
            'title' => 'Phishing led to mailbox compromise',
            'category_id' => $this->lookupId(Lookup::TYPE_INCIDENT_CATEGORY),
            'severity' => Incident::SEVERITY_HIGH,
            'status' => Incident::STATUS_TRIAGE,
            'detected_at' => '2026-06-01 09:00:00',
        ]);
        $this->assertTrue($incident->save(), json_encode($incident->getErrors()));
        $edr = $this->makeControl(['title' => 'EDR alerting', 'status' => Control::STATUS_IMPLEMENTED]);
        $mfa = $this->makeControl(['title' => 'MFA', 'status' => Control::STATUS_IMPLEMENTED]);

        $incident->detectedByControlIds = [$edr->id];
        $incident->failedControlIds = [$mfa->id];
        $incident->responseControlIds = [$mfa->id];
        $this->assertTrue($incident->save(), json_encode($incident->getErrors()));
        $byRole = static fn(Incident $i) => array_map(static fn($cs) => array_map(static fn($c) => $c->id, $cs), $i->getControlsByRole());
        $this->assertSame(['detected' => [$edr->id], 'failed' => [$mfa->id], 'response' => [$mfa->id]], $byRole($incident));

        // Changing one role leaves the others alone
        $reloaded = Incident::findOne($incident->id);
        $reloaded->failedControlIds = [];
        $this->assertTrue($reloaded->save(), json_encode($reloaded->getErrors()));
        $this->assertSame(['detected' => [$edr->id], 'failed' => [], 'response' => [$mfa->id]], $byRole($reloaded));
        $reloaded->populateLinks();
        $this->assertSame([$mfa->id], $reloaded->responseControlIds);

        $audit = AuditLog::find()->where(['entity_type' => 'incident', 'entity_id' => $incident->id, 'action' => 'update'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertSame([[$mfa->reference], []], $audit->getChangesArray()['failedControlIds']);

        $this->assertSame([$incident->id], array_map(static fn($i) => $i->id, Control::findOne($mfa->id)->getIncidentsByRole()['response']));
    }

    public function testDeviceCheckScopeIsNormalised(): void
    {
        $all = $this->makeControl(['device_check' => Control::CHECK_MANUAL, 'deviceTypeList' => array_keys(Device::typeOptions())]);
        $this->assertNull($all->device_types, 'every type ticked = all types');

        $patch = new Control(['title' => 'Patching', 'status' => 'implemented', 'control_type' => 'preventive', 'nature' => 'technical',
            'device_check' => Control::CHECK_PATCH_AGE, 'check_max_age_days' => '']);
        $this->assertFalse($patch->validate(), 'a patch-age check needs a maximum age');
        $patch->check_max_age_days = 14;
        $this->assertTrue($patch->save(), json_encode($patch->getErrors()));

        $os = $this->makeControl(['device_check' => Control::CHECK_OS_SUPPORT, 'check_max_age_days' => 30, 'deviceTypeList' => ['laptop']]);
        $this->assertNull($os->check_max_age_days, 'only a patch-age check has a maximum age');
        $this->assertSame('laptop', $os->device_types);
        $this->assertTrue(\app\modules\inventory\components\DeviceChecks::fromControl($os)->isOsSupport());

        // Removing the device check clears its scope
        $scoped = $this->makeControl(['device_check' => Control::CHECK_MANUAL, 'deviceTypeList' => ['laptop'], 'device_ownership' => 'byod']);
        $this->assertSame('laptop', $scoped->device_types);
        $scoped->device_check = '';
        $this->assertTrue($scoped->save());
        $this->assertNull($scoped->device_types);
        $this->assertSame(Control::OWNERSHIP_ALL, $scoped->device_ownership);

        $this->assertSame('CTL', substr($scoped->reference, 0, 3));
        $this->assertSame(Yii::$app->formatter->asDate(date('Y-m-d')), Yii::$app->formatter->asDate(
            $this->makeControl(['status' => Control::STATUS_IMPLEMENTED])->implemented_on), 'implemented_on defaults to today');
    }
}
