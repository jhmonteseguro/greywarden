<?php

namespace tests\functional;

use app\models\AuditLog;
use app\models\Setting;
use app\modules\report\components\ReportBuilder;
use app\modules\report\components\ReportPeriod;
use app\modules\report\models\Report;
use tests\FunctionalTestCase;

/**
 * Management reports through the real controllers against the demo data: the
 * list and its filters, the report page and PDF, creating a report for a preset
 * or custom period, editing the summary, regenerating, approving and
 * withdrawing, permissions, search and the risk appetite setting.
 */
class ReportFlowsTest extends FunctionalTestCase
{
    protected static bool $seed = true;

    private function approved(): Report
    {
        $report = Report::findOne(['status' => Report::STATUS_APPROVED]);
        $this->assertNotNull($report, 'approved report missing from the demo data');
        return $report;
    }

    private function draft(): Report
    {
        $report = Report::findOne(['status' => Report::STATUS_DRAFT]);
        $this->assertNotNull($report, 'draft report missing from the demo data');
        return $report;
    }

    public function testListAndFilters(): void
    {
        $approved = $this->approved();
        $draft = $this->draft();
        $this->loginAs('viewer');
        $this->get('/reports');
        $this->assertOk();
        $this->assertSee($approved->reference);
        $this->assertSee($draft->reference);
        $this->assertSee('data-kind="rag"');
        $this->assertNotSee('New report');

        $this->get('/reports?filter=approved');
        $this->assertSee($approved->reference);
        $this->assertNotSee($draft->reference);
        $this->get('/reports?filter=draft');
        $this->assertSee($draft->reference);
        $this->assertNotSee($approved->reference);
        $this->get('/reports?filter=archived');
        $this->assertSee('No records match the current filters.');
        $this->get('/reports?q=board&sort=-rag');
        $this->assertSee($approved->reference);
        $this->assertNotSee($draft->reference);
        $this->get('/reports?status=draft&period_type=month&rag=' . $draft->rag . '&reference=RPT&title=posture');
        $this->assertSee($draft->reference);
        $this->get('/reports?filter=nope');
        $this->assertSee('No records match the current filters.');

        $this->loginAs('analyst');
        $this->get('/reports');
        $this->assertSee('New report');
        $this->assertSee('/reports/' . $draft->id . '/update');
        $this->assertNotSee('/reports/' . $approved->id . '/update');
    }

    public function testReportPageAndPdf(): void
    {
        $report = $this->approved();
        $this->loginAs('viewer');
        $this->get('/reports/' . $report->id);
        $this->assertOk();
        foreach (['At a glance', 'Overall status', 'Risks above appetite', '1. Risk', '2. Incidents', '3. Controls and compliance', '4. Decisions',
                     '5. Assets and exposure', '6. Suppliers', 'Looking ahead', 'How the figures are worked out', 'We ask the board',
                     'Approved by', 'Reviewed with the leadership team.', 'Residual heatmap'] as $text) {
            $this->assertSee($text);
        }
        $this->assertSee('class="gw-report"');
        $this->assertSee('/search?q=RSK-');   // references link to their records
        $this->assertNotSee('Approve report');
        $this->assertNotSee('Withdraw approval');
        $this->assertNotSee('Regenerate figures');
        $this->assertNotSee('gw-btn--edit');

        $this->get('/reports/' . $report->id . '/pdf');
        $this->assertOk();
        $this->assertStringStartsWith('%PDF-', $this->body);
        $this->assertSame('export', AuditLog::find()->where(['entity_type' => 'report', 'entity_id' => $report->id])->orderBy(['id' => SORT_DESC])->one()->action);

        $draft = $this->draft();
        $this->get('/reports/' . $draft->id . '/pdf');
        $this->assertOk();

        // Analysts see the draft's sign-off panel; the approved report has none
        $this->loginAs('analyst');
        $this->get('/reports/' . $draft->id);
        $this->assertSee('Regenerate figures');
        $this->assertSee('Before it can be approved: Write the management summary.');
        $this->assertSee('gw-btn--edit');
    }

    public function testCreateForPresetAndCustomPeriods(): void
    {
        $this->loginAs('analyst');
        $this->get('/reports/create');
        $this->assertOk();
        $this->assertSee('Last month:');
        $this->assertSee('Custom dates (below)');

        $today = date('Y-m-d');
        $cases = [
            [['period_start' => '2026-09-10', 'period_end' => '2026-09-01'], 'The period cannot end before it starts.'],
            [['period_start' => '2026-09-01', 'period_end' => date('Y-m-d', strtotime('+1 day'))], 'The period cannot end in the future'],
            [['period_start' => '2024-01-01', 'period_end' => $today], 'A report covers at most'],
            [['period_start' => 'yesterday', 'period_end' => $today], 'The format of From is invalid.'],
        ];
        foreach ($cases as [$dates, $error]) {
            $this->post('/reports/create', ['Report' => ['preset' => 'custom', 'period_type' => 'custom'] + $dates]);
            $this->assertOk();
            $this->assertSee($error);
        }
        $this->post('/reports/create', ['Report' => ['preset' => 'nope']]);
        $this->assertSee('Period is invalid.');

        $before = (int) Report::find()->max('id');
        $this->post('/reports/create', ['Report' => ['preset' => 'custom', 'period_type' => 'custom', 'period_start' => '2026-08-15', 'period_end' => '2026-09-14', 'title' => '']]);
        $this->assertRedirect('/reports/');
        $report = Report::find()->where(['>', 'id', $before])->one();
        $this->assertSame('Security posture report · 15 Aug – 14 Sep 2026', $report->title);
        $this->assertTrue($report->hasReadableFigures());
        $this->assertSame($report->getFigures()['suggested_rag'], $report->rag);
        $this->assertSame(Report::STATUS_DRAFT, $report->status);
        $this->assertNotNull($report->generated_at);
        $this->assertStringContainsString('created', $this->flash('success'));

        $this->post('/reports/create', ['Report' => ['preset' => 'last-quarter', 'title' => 'Board pack Q3']]);
        $this->assertRedirect();
        $quarter = Report::find()->orderBy(['id' => SORT_DESC])->one();
        $preset = ReportPeriod::presets()['last-quarter'];
        $this->assertSame([ReportPeriod::QUARTER, $preset['start'], $preset['end'], 'Board pack Q3'],
            [$quarter->period_type, $quarter->period_start, $quarter->period_end, $quarter->title]);
        // The demo's approved Q3 report ended before this one started? No: same period, so it is not "previous"
        $this->assertNull($quarter->getFigures()['previous_report']);
    }

    public function testEditApproveAndWithdraw(): void
    {
        $draft = $this->draft();
        $this->loginAs('analyst');

        // Not ready: the approval is refused with the reasons
        $this->post('/reports/' . $draft->id . '/approve');
        $this->assertRedirect();
        $this->assertStringContainsString('Write the management summary.', $this->flash('warning'));

        $this->get('/reports/' . $draft->id . '/update');
        $this->assertOk();
        $this->assertSee('The figures suggest');
        $this->post('/reports/' . $draft->id . '/update', ['Report' => ['title' => '', 'rag' => 'purple', 'management_summary' => 'x']]);
        $this->assertOk();
        $this->assertSee('Title cannot be blank.');
        $this->assertSee('Overall status is invalid.');
        $this->post('/reports/' . $draft->id . '/update', ['Report' => ['title' => 'October so far', 'rag' => 'amber',
            'management_summary' => "Mostly on track.\nTwo overdue tests.", 'period_start' => '2020-01-01']]);
        $this->assertRedirect();
        $draft->refresh();
        $this->assertSame(['October so far', 'amber'], [$draft->title, $draft->rag]);
        $this->assertNotSame('2020-01-01', $draft->period_start, 'the period cannot be changed after creation');

        $this->get('/reports/' . $draft->id);
        $this->assertSee('Approve report');
        $this->post('/reports/' . $draft->id . '/approve', ['approval_note' => '  Agreed at the board  ']);
        $this->assertRedirect();
        $draft->refresh();
        $this->assertTrue($draft->isApproved());
        $this->assertSame([$this->userIdFor('analyst'), 'Agreed at the board'], [(int) $draft->approved_by_id, $draft->approval_note]);

        // Approved: read-only
        $this->get('/reports/' . $draft->id . '/update');
        $this->assertRedirect();
        $this->assertStringContainsString('read-only', $this->flash('warning'));
        $this->post('/reports/' . $draft->id . '/regenerate');
        $this->assertStringContainsString('Only draft reports', $this->flash('warning'));
        $this->post('/reports/' . $draft->id . '/approve');
        $this->assertStringContainsString('already approved', $this->flash('warning'));

        // Only administrators withdraw an approval
        $this->post('/reports/' . $draft->id . '/withdraw');
        $this->assertStatus(403);
        $this->loginAs('admin');
        $this->get('/reports/' . $draft->id);
        $this->assertSee('Withdraw approval');
        $this->post('/reports/' . $draft->id . '/withdraw');
        $this->assertRedirect();
        $draft->refresh();
        $this->assertTrue($draft->isDraft());
        $this->assertNull($draft->approved_by_id);
        $this->post('/reports/' . $draft->id . '/withdraw');
        $this->assertStringContainsString('Only approved reports', $this->flash('warning'));

        $this->get('/reports/' . $draft->id);
        $this->assertSee('Approval withdrawn');
        $this->assertSee('Approved');
        $actions = AuditLog::find()->select('action')->where(['entity_type' => 'report', 'entity_id' => $draft->id])->orderBy('id')->column();
        $this->assertSame(['update', 'approve', 'approval_withdraw'], array_values(array_slice($actions, -3)));
    }

    public function testRegenerateAndArchive(): void
    {
        $draft = $this->draft();
        Report::updateAll(['generated_at' => '2026-01-01 00:00:00', 'data' => '{"version":0}'], ['id' => $draft->id]);
        $this->loginAs('analyst');
        $this->get('/reports/' . $draft->id);
        $this->assertSee('made by another version of Greywarden');
        $this->get('/reports/' . $draft->id . '/pdf');
        $this->assertOk();

        $this->post('/reports/' . $draft->id . '/regenerate');
        $this->assertRedirect();
        $this->assertStringContainsString('worked out again', $this->flash('success'));
        $draft->refresh();
        $this->assertTrue($draft->hasReadableFigures());
        $this->assertNotSame('2026-01-01 00:00:00', $draft->generated_at);
        $this->get('/reports/' . $draft->id);
        $this->assertSee('Figures regenerated');

        $this->post('/reports/' . $draft->id . '/archive');
        $this->assertRedirect();
        $this->post('/reports/' . $draft->id . '/regenerate');
        $this->assertStringContainsString('Only draft reports', $this->flash('warning'));
        $this->post('/reports/' . $draft->id . '/approve');
        $this->assertStringContainsString('archived', $this->flash('warning'));
        $this->get('/reports/' . $draft->id);
        $this->assertNotSee('Regenerate figures');
        $this->assertSee('It is read-only until restored.');

        $approved = $this->approved();
        $this->post('/reports/' . $approved->id . '/archive');
        $this->loginAs('admin');
        $this->post('/reports/' . $approved->id . '/withdraw');
        $this->assertStringContainsString('Only approved reports', $this->flash('warning'));
        $this->post('/reports/' . $approved->id . '/delete');
        $this->assertRedirect();
        $this->assertNull(Report::findOne($approved->id));
    }

    public function testViewersCannotChangeReports(): void
    {
        $draft = $this->draft();
        $this->loginAs('viewer');
        foreach (['/reports/create' => 'get', "/reports/{$draft->id}/update" => 'get', "/reports/{$draft->id}/approve" => 'post',
                     "/reports/{$draft->id}/regenerate" => 'post', "/reports/{$draft->id}/withdraw" => 'post'] as $url => $method) {
            $this->$method($url);
            $this->assertStatus(403, $url);
        }
        $this->get('/reports/999999');
        $this->assertStatus(404);
        $this->loginAs('analyst');
        $this->get("/reports/{$draft->id}/approve");
        $this->assertStatus(405);
    }

    public function testSearchFindsReports(): void
    {
        $report = $this->approved();
        $this->loginAs('viewer');
        $this->get('/search?q=' . $report->reference);
        $this->assertRedirect('/reports/' . $report->id);
        $this->get('/search?q=posture+report');
        $this->assertSee('Management reports');
        $this->assertSee($report->reference);
        $this->assertSee('data-kind="report-status"');
    }

    public function testRiskAppetiteSetting(): void
    {
        $this->loginAs('admin');
        $this->get('/admin/settings');
        $this->assertSee('Management reports');
        $this->assertSee('9 · Medium');

        $this->post('/admin/settings', ['ReportForm' => ['risk_appetite' => '30']]);
        $this->assertOk();
        $this->assertSee('must be no greater than 25');

        $this->post('/admin/settings', ['ReportForm' => ['risk_appetite' => '12']]);
        $this->assertRedirect('#management-reports');
        Setting::reset();
        $this->assertSame(12, ReportBuilder::appetite());
        $entry = AuditLog::find()->where(['entity_type' => 'setting', 'entity_label' => 'Management reports'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertSame(['risk_appetite' => [9, 12]], $entry->getChangesArray());

        // A new report uses it
        $this->loginAs('analyst');
        $this->post('/reports/create', ['Report' => ['preset' => 'last-month']]);
        $report = Report::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertSame(12, $report->getFigures()['appetite']);
        $this->get('/reports/' . $report->id);
        $this->assertSee('residual scores up to <strong>12</strong>');

        // Back to the default: stored empty, so it follows the built-in value
        $this->loginAs('admin');
        $this->post('/admin/settings', ['ReportForm' => ['risk_appetite' => (string) ReportBuilder::DEFAULT_APPETITE]]);
        Setting::reset();
        $this->assertSame('', Setting::get(ReportBuilder::SETTING_APPETITE));
        $this->assertSame(ReportBuilder::DEFAULT_APPETITE, ReportBuilder::appetite());

        $this->loginAs('analyst');
        $this->get('/admin/settings');
        $this->assertStatus(403);
    }
}
