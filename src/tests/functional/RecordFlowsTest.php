<?php

namespace tests\functional;

use app\models\AuditLog;
use app\models\Lookup;
use app\modules\control\models\Control;
use app\modules\control\models\ControlTest;
use app\modules\control\models\FrameworkRequirement;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\incident\models\IncidentTimeline;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use tests\FunctionalTestCase;
use yii\db\Query;

/**
 * Create / update / archive / restore / delete and the record-specific actions
 * (incident timeline, control tests, Statement of Applicability, PDF export,
 * global search) through the real controllers, against the demo data.
 */
class RecordFlowsTest extends FunctionalTestCase
{
    protected static bool $seed = true;

    private function id(string $table, string $column, string $value): int
    {
        return (int) (new Query())->select('id')->from($table)->where([$column => $value])->scalar();
    }

    private function riskForm(array $attrs = []): array
    {
        return ['Risk' => $attrs + [
            'title' => 'Functional risk', 'status' => Risk::STATUS_ASSESSED, 'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY),
            'owner_id' => $this->userIdFor('analyst'), 'date_identified' => date('Y-m-d'),
            'inherent_likelihood' => 4, 'inherent_impact' => 4, 'residual_likelihood' => 2, 'residual_impact' => 3,
            'target_likelihood' => 1, 'target_impact' => 3, 'treatment' => Risk::TREATMENT_MITIGATE,
            'deviceIds' => '', 'incidentIds' => '', 'decisionIds' => '',
        ]];
    }

    private function lastId(string $table): int
    {
        return (int) (new Query())->from($table)->max('id');
    }

    public function testRiskLifecycle(): void
    {
        $this->loginAs('analyst');
        $this->get('/risks/create');
        $this->assertOk();
        $this->assertSee(date('Y-m-d'));
        // Scoring guide: every level explained, rating bands from the matrix
        $this->assertSee('How to score likelihood and impact');
        $this->assertSee('Over 90%, or several times a year.');
        $this->assertSee('Threatens the business');
        $this->assertSee('Critical 20–25');

        // Residual above inherent is rejected
        $this->post('/risks/create', $this->riskForm(['inherent_likelihood' => 2, 'inherent_impact' => 2, 'residual_likelihood' => 5, 'residual_impact' => 5]));
        $this->assertOk();
        $this->assertSee('cannot exceed the inherent score');

        $device = $this->id('device', 'asset_tag', 'DEV-0001');
        $this->post('/risks/create', $this->riskForm(['deviceIds' => [$device]]));
        $this->assertRedirect('/risks/');
        $risk = Risk::findOne($this->lastId('risk'));
        $this->assertSame('Functional risk', $risk->title);
        $this->assertSame(16, (int) $risk->inherent_score);
        $this->assertSame(6, (int) $risk->residual_score);
        $this->assertSame(3, (int) $risk->target_score);
        $this->assertSame([$device], array_map('intval', array_column($risk->devices, 'id')));
        $this->assertStringContainsString("Risk {$risk->reference} created.", $this->flash('success'));

        $this->get("/risks/{$risk->id}");
        $this->assertOk();
        $this->assertSee($risk->reference);
        $this->assertSee('DEV-0001');

        $this->post("/risks/{$risk->id}/update", $this->riskForm(['title' => 'Functional risk (accepted)', 'status' => Risk::STATUS_ACCEPTED,
            'treatment' => Risk::TREATMENT_ACCEPT, 'acceptance_justification' => 'Within appetite', 'residual_likelihood' => 1]));
        $this->assertRedirect("/risks/{$risk->id}");
        $risk->refresh();
        $this->assertSame(Risk::STATUS_ACCEPTED, $risk->status);
        $this->assertSame([], array_column($risk->devices, 'id'), 'empty link field clears the links');

        $this->get("/risks/{$risk->id}");
        $this->assertSee('Within appetite');
        $this->assertSee('gw-diff');

        // Hard delete needs the record to be archived first
        $this->loginAs('admin');
        $this->post("/risks/{$risk->id}/delete");
        $this->assertRedirect("/risks/{$risk->id}");
        $this->assertStringContainsString('Archive the record before deleting', $this->flash('warning'));

        $this->loginAs('analyst');
        $this->post("/risks/{$risk->id}/archive");
        $this->assertRedirect('/risks');
        $this->assertTrue(Risk::findOne($risk->id)->getIsArchived());
        $this->get("/risks/{$risk->id}/update");
        $this->assertRedirect("/risks/{$risk->id}");
        $this->assertStringContainsString('read-only', $this->flash('warning'));
        $this->get('/risks?filter=archived');
        $this->assertSee($risk->reference);

        $this->post("/risks/{$risk->id}/restore");
        $this->assertRedirect("/risks/{$risk->id}");
        $this->assertFalse(Risk::findOne($risk->id)->getIsArchived());

        $this->post("/risks/{$risk->id}/archive");
        $this->loginAs('admin');
        $this->post("/risks/{$risk->id}/delete");
        $this->assertRedirect('filter=archived');
        $this->assertNull(Risk::findOne($risk->id));
        $actions = AuditLog::find()->select('action')->where(['entity_type' => 'risk', 'entity_id' => $risk->id])->orderBy('id')->column();
        $this->assertSame(['create', 'update', 'archive', 'restore', 'archive', 'delete'], $actions);
    }

    public function testDecisionCreateUpdateAndSupersede(): void
    {
        $this->loginAs('analyst');
        $risk = $this->id('risk', 'reference', 'RSK-0001');
        $mitigation = $this->id('attack_mitigation', 'external_id', 'M1017');
        $form = ['Decision' => [
            'title' => 'Functional decision', 'status' => Decision::STATUS_PROPOSED, 'decision_date' => date('Y-m-d'),
            'category_id' => $this->lookupId(Lookup::TYPE_DECISION_CATEGORY), 'decision_makers' => 'CISO', 'decision_text' => 'Do the thing',
            'riskIds' => [$risk], 'incidentIds' => '', 'mitigationIds' => [$mitigation],
        ]];
        $this->post('/decisions/create', ['Decision' => ['title' => '']]);
        $this->assertOk();
        $this->assertSee('cannot be blank');

        $this->post('/decisions/create', $form);
        $this->assertRedirect('/decisions/');
        $decision = Decision::findOne($this->lastId('decision'));
        $this->get("/decisions/{$decision->id}");
        $this->assertSee('M1017');
        $this->assertSee('RSK-0001');

        $form['Decision']['status'] = Decision::STATUS_SUPERSEDED;
        $form['Decision']['superseded_by_id'] = $this->id('decision', 'reference', 'DEC-0001');
        $this->post("/decisions/{$decision->id}/update", $form);
        $this->assertRedirect();
        $this->get("/decisions/{$decision->id}");
        $this->assertSee('Superseded by');
    }

    public function testIncidentTimelineAndStatusChanges(): void
    {
        $this->loginAs('analyst');
        $this->get('/incidents/create');
        $this->assertOk();

        $technique = $this->id('attack_technique', 'external_id', 'T1566.001');
        $form = ['Incident' => [
            'title' => 'Functional incident', 'category_id' => $this->lookupId(Lookup::TYPE_INCIDENT_CATEGORY), 'severity' => Incident::SEVERITY_HIGH,
            'status' => Incident::STATUS_NEW, 'detected_at' => date('Y-m-d\TH:i'), 'personal_data_involved' => 1, 'regulator_notification_required' => 1,
            'impact_confidentiality' => 1, 'impact_integrity' => 0, 'impact_availability' => 1,
            'management_summary' => 'Plain summary for the board',
            'deviceIds' => '', 'riskIds' => '', 'techniqueIds' => [$technique],
        ]];
        $this->post('/incidents/create', $form);
        $this->assertRedirect('/incidents/');
        $incident = Incident::findOne($this->lastId('incident'));
        $this->get("/incidents/{$incident->id}");
        $this->assertSee('T1566.001');
        $this->assertSee('data-gw-countdown');
        $this->assertSee('Security impact');
        $this->assertSee('data-value="confidentiality">Confidentiality<');
        $this->assertSee('data-value="availability">Availability<');
        $this->assertNotSee('data-value="integrity"');
        $this->assertSee('Plain summary for the board');
        $this->assertSee('href="/incidents/' . $incident->id . '/pdf"');
        $this->get('/incidents?q=board');
        $this->assertSee('Functional incident');
        $this->get('/incidents?impact=availability');
        $this->assertSee('Functional incident');
        $this->get('/incidents?impact=integrity');
        $this->assertNotSee('Functional incident');

        $this->post("/incidents/{$incident->id}/add-entry", ['IncidentTimeline' => ['entry_type' => IncidentTimeline::TYPE_ACTION, 'entry_at' => date('Y-m-d\TH:i'), 'body' => 'Functional timeline entry']]);
        $this->assertRedirect("/incidents/{$incident->id}");
        $this->assertSame('Timeline entry added.', $this->flash('success'));
        // Status entries are system-generated only: a posted one becomes a note
        $this->post("/incidents/{$incident->id}/add-entry", ['IncidentTimeline' => ['entry_type' => IncidentTimeline::TYPE_STATUS, 'entry_at' => date('Y-m-d\TH:i'), 'body' => 'Faked status']]);
        $this->assertSame(IncidentTimeline::TYPE_NOTE, IncidentTimeline::find()->where(['incident_id' => $incident->id, 'body' => 'Faked status'])->one()->entry_type);
        $this->post("/incidents/{$incident->id}/add-entry", ['IncidentTimeline' => ['entry_type' => 'note', 'body' => '']]);
        $this->assertStringContainsString('Timeline entry not added', $this->flash('danger'));

        $form['Incident']['status'] = Incident::STATUS_CONTAINED;
        $this->post("/incidents/{$incident->id}/update", $form);
        $this->assertRedirect();
        $this->get("/incidents/{$incident->id}");
        $this->assertSee('Functional timeline entry');
        $this->assertSee('Status changed from New to Contained');

        $this->post("/incidents/{$incident->id}/archive");
        $this->post("/incidents/{$incident->id}/add-entry", ['IncidentTimeline' => ['entry_type' => 'note', 'body' => 'Too late']]);
        $this->assertStringContainsString('read-only', $this->flash('warning'));
        $this->assertFalse(IncidentTimeline::find()->where(['body' => 'Too late'])->exists());
    }

    public function testDeviceValidationAndPosture(): void
    {
        $this->loginAs('analyst');
        $base = ['hostname' => 'fn-host', 'device_type' => 'laptop', 'status' => 'active', 'criticality' => 'low', 'data_classification' => 'internal'];
        $this->post('/devices/create', ['Device' => $base + ['mac_address' => 'zz:zz', 'ip_addresses' => '10.0.0.999']]);
        $this->assertOk();
        $this->assertSee('Enter a MAC address');
        $this->assertSee('Invalid IP address');

        $this->post('/devices/create', ['Device' => ['device_type' => 'mobile', 'ownership' => 'byod', 'status' => 'active', 'criticality' => 'low', 'data_classification' => 'internal']]);
        $this->assertSee('Enter a device name, a hostname, or both');

        $this->post('/devices/create', ['Device' => $base + ['mac_address' => 'aa-bb-cc-dd-ee-ff', 'ip_addresses' => '10.0.0.9, fe80::1']]);
        $this->assertRedirect('/devices/');
        $device = Device::findOne($this->lastId('device'));
        $this->assertSame('AA:BB:CC:DD:EE:FF', $device->mac_address);
        $this->assertMatchesRegularExpression('/^DEV-\d{4,}$/', $device->asset_tag);
        $this->get("/devices/{$device->id}");
        $this->assertOk();
        $this->get("/devices/{$device->id}/update");
        $this->assertOk();
    }

    public function testControlTestsAndArchivedControls(): void
    {
        $this->loginAs('analyst');
        $this->post('/controls/create', ['Control' => ['title' => '', 'status' => 'planned', 'control_type' => 'preventive', 'nature' => 'technical']]);
        $this->assertSee('Title cannot be blank');
        $this->post('/controls/create', ['Control' => ['title' => 'Patch check', 'status' => 'implemented', 'control_type' => 'preventive',
            'nature' => 'technical', 'device_check' => 'patch_age', 'check_max_age_days' => '']]);
        $this->assertSee('Set the maximum patch age');

        $req = $this->id('framework_requirement', 'external_id', 'A.8.7');
        $risk = $this->id('risk', 'reference', 'RSK-0001');
        $this->post('/controls/create', ['Control' => [
            'title' => 'Functional control', 'status' => 'implemented', 'control_type' => 'detective', 'nature' => 'technical',
            'owner_id' => $this->userIdFor('analyst'), 'test_frequency_months' => 3, 'device_check' => 'manual', 'deviceTypeList' => ['laptop'],
            'device_ownership' => 'all', 'requirementIds' => [$req], 'mitigationIds' => '', 'riskIds' => [$risk], 'decisionIds' => '',
        ]]);
        $this->assertRedirect('/controls/');
        $control = Control::findOne($this->lastId('control'));
        $this->get("/controls/{$control->id}");
        $this->assertSee('A.8.7');
        $this->assertSee('tab-devices');

        $this->post("/controls/{$control->id}/record-test", ['ControlTest' => ['tested_on' => date('Y-m-d'), 'result' => '']]);
        $this->assertOk();
        $this->assertSee('Result cannot be blank');

        $this->post("/controls/{$control->id}/record-test", ['ControlTest' => ['tested_on' => date('Y-m-d'), 'result' => 'ineffective',
            'tested_by_id' => $this->userIdFor('analyst'), 'method' => 'Sample', 'notes' => 'Functional findings']]);
        $this->assertRedirect("/controls/{$control->id}");
        $this->assertStringContainsString('is ineffective', $this->flash('success'));
        $test = ControlTest::findOne(['control_id' => $control->id]);
        $this->get('/risks?filter=control-issues');
        $this->assertSee('RSK-0001');

        $this->post("/controls/{$control->id}/delete-test?test=999999");
        $this->assertStatus(404);
        $this->post("/controls/{$control->id}/delete-test?test={$test->id}");
        $this->assertRedirect();
        $this->assertNull(ControlTest::findOne($test->id));

        $test2 = new ControlTest(['control_id' => $control->id, 'tested_on' => date('Y-m-d'), 'result' => 'effective']);
        $test2->save(false);
        $this->post("/controls/{$control->id}/archive");
        $this->post("/controls/{$control->id}/record-test", ['ControlTest' => ['tested_on' => date('Y-m-d'), 'result' => 'effective']]);
        $this->assertStringContainsString('Restore the control', $this->flash('warning'));
        $this->post("/controls/{$control->id}/delete-test?test={$test2->id}");
        $this->assertStringContainsString('read-only', $this->flash('warning'));
        $this->assertNotNull(ControlTest::findOne($test2->id));
    }

    public function testStatementOfApplicability(): void
    {
        $req = $this->id('framework_requirement', 'external_id', 'A.8.7');
        $this->loginAs('analyst');
        $this->post("/frameworks/requirement/$req", ['FrameworkRequirement' => ['applicable' => 0, 'justification' => '']]);
        $this->assertOk();
        $this->assertSee('Say why this requirement does not apply');

        $this->post("/frameworks/requirement/$req", ['FrameworkRequirement' => ['applicable' => 0, 'justification' => 'Functional exclusion']]);
        $this->assertRedirect("/frameworks/requirement/$req");
        $this->assertFalse((bool) FrameworkRequirement::findOne($req)->applicable);
        $this->get('/frameworks/iso27001');
        $this->assertSee('Functional exclusion');
        $this->get('/frameworks/iso27001?state=excluded');
        $this->assertOk();
    }

    public function testPdfExportIsAudited(): void
    {
        $this->loginAs('viewer');
        foreach (['risks' => ['risk', 'RSK-0001'], 'controls' => ['control', 'CTL-0001'], 'decisions' => ['decision', 'DEC-0001'],
                     'incidents' => ['incident', 'INC-0001']] as $path => [$table, $ref]) {
            $id = $this->id($table, 'reference', $ref);
            $this->get("/$path/$id/pdf");
            $this->assertOk();
            $this->assertStringStartsWith('%PDF-', $this->body);
            $this->assertSame('application/pdf', $this->response->headers->get('content-type'));
            $this->assertStringContainsString("$ref-", (string) $this->response->headers->get('content-disposition'));
            $this->assertTrue(AuditLog::find()->where(['entity_type' => $table, 'entity_id' => $id, 'action' => 'export'])->exists());
        }
        // Devices have no PDF
        $this->get('/devices/' . $this->id('device', 'asset_tag', 'DEV-0001') . '/pdf');
        $this->assertStatus(403);
    }

    public function testSearchJumpsToExactMatches(): void
    {
        $this->loginAs('viewer');
        foreach (['RSK-0001' => '/risks/', 'CTL-0001' => '/controls/', 'DEC-0001' => '/decisions/', 'INC-0001' => '/incidents/', 'DEV-0001' => '/devices/',
                     'BLK-0001' => '/intel/blocklist/', 'T1566.001' => '/attack/T1566.001', 'M1032' => '/attack/mitigations/M1032', 'A.8.7' => '/frameworks/requirement/'] as $q => $target) {
            $this->get('/search?q=' . urlencode($q));
            $this->assertRedirect($target);
        }
        $this->get('/search?q=phishing');
        $this->assertOk();
        $this->assertSee('ATT&amp;CK techniques');
        $this->get('/search?q=203.0.113.66');
        $this->assertSee('/intel/ip/203.0.113.66');
    }

    public function testListFiltersSearchAndInvalidParameters(): void
    {
        $this->loginAs('viewer');
        foreach (['/risks', '/decisions', '/incidents', '/devices', '/controls', '/intel/blocklist'] as $list) {
            foreach (['?filter=archived', '?filter=all&q=a', '?filter=all&page=2', '?sort=-id&filter=all'] as $query) {
                $this->get($list . $query);
                $this->assertOk();
            }
        }
        // Invalid filter values give an empty list, not an error
        foreach (['/risks?inherent_likelihood=abc', '/decisions?status[]=x', '/incidents?month=never', '/devices?criticality[]=x',
                     '/controls?owner_id=abc'] as $url) {
            $this->get($url);
            $this->assertOk();
            $this->assertTrue(str_contains($this->body, 'No records match the current filters.'), "$url is not empty");
        }
        $this->get('/intel/blocklist?severity[]=x');
        $this->assertSee('No addresses match.');
        $this->get('/devices?filter=active&control=999999');
        $this->assertOk();
        $this->assertSee('No records match the current filters.');
        $this->get('/devices?filter=all&q=DEV-0001');
        $this->assertSee('DEV-0001');
        $this->get('/risks?filter=all&inherent_rating=critical&residual_rating=high&target_rating=low');
        $this->assertOk();
    }

    public function testArchivedRecordShowsRestoreAndDeleteToAdmins(): void
    {
        $risk = Risk::findOne(['reference' => 'RSK-0002']);
        $risk->archive();
        $this->loginAs('admin');
        $this->get("/risks/{$risk->id}");
        $this->assertSee('gw-btn--restore');
        $this->assertSee('gw-btn--delete');
        $this->loginAs('analyst');
        $this->get("/risks/{$risk->id}");
        $this->assertSee('gw-btn--restore');
        $this->assertNotSee('gw-btn--delete');
        $this->loginAs('viewer');
        $this->get("/risks/{$risk->id}");
        $this->assertNotSee('gw-btn--restore');
    }

    public function testIncidentSeverityChangeAndReopeningAreLogged(): void
    {
        $incident = Incident::findOne(['reference' => 'INC-0001']);
        $incident->status = Incident::STATUS_CLOSED;
        $this->assertTrue($incident->save(), json_encode($incident->getErrors()));
        $this->assertNotNull($incident->closed_at);
        $incident->status = Incident::STATUS_INVESTIGATING;
        $incident->severity = $incident->severity === Incident::SEVERITY_LOW ? Incident::SEVERITY_HIGH : Incident::SEVERITY_LOW;
        $this->assertTrue($incident->save(), json_encode($incident->getErrors()));
        $this->assertNull($incident->closed_at, 're-opened');
        $bodies = IncidentTimeline::find()->select('body')->where(['incident_id' => $incident->id, 'entry_type' => IncidentTimeline::TYPE_STATUS])->column();
        $this->assertNotEmpty(preg_grep('/^Severity changed from /', $bodies));
    }

    public function testDeviceOsSupportAndLastSeenFlags(): void
    {
        $this->loginAs('viewer');
        // The demo data has Windows 10 laptops and a Windows Server 2012 R2 server
        $this->get('/devices?filter=unsupported-os');
        $this->assertOk();
        $this->assertSee('Windows Server 2012 R2');
        $this->assertSee('data-value="os-unsupported"');
        $this->assertNotSee('Windows Server 2022');

        $old = Device::findOne(['os' => 'Windows Server 2012 R2']);
        $this->get("/devices/{$old->id}");
        $this->assertSee('OS support');
        $this->assertSee('Unsupported OS');
        // ... and fails the supported-OS device check
        $this->assertSee('Windows Server 2012 R2 6.3: security updates ended 10 Oct 2023');
        $this->assertSee('data-value="non-compliant"');
        $check = \app\modules\control\models\Control::findOne(['device_check' => 'os_support']);
        $this->get("/controls/{$check->id}");
        $this->assertOk();
        $this->assertSee('past its vendor end of support');

        Device::updateAll(['last_seen_at' => date('Y-m-d H:i:s', strtotime('-200 days')), 'source' => 'google', 'source_id' => 'fn-missing'], ['id' => $old->id]);
        $this->get("/devices/{$old->id}");
        $this->assertSee('data-value="missing"');
        $this->get('/devices?filter=stale');
        $this->assertSee('gw-text-danger');
    }

    public function testHeatmapAndDashboard(): void
    {
        $this->loginAs('viewer');
        $this->get('/risks/heatmap');
        $this->assertOk();
        $this->get('/');
        $this->assertOk();
        $this->assertSee('gw-attention__group--stale-devices');
        $this->assertSee('gw-attention__group--unsupported-os');
        $this->assertSee('gw-attention__group--control-tests-soon');
    }
}
