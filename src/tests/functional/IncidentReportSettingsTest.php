<?php

namespace tests\functional;

use app\models\AuditLog;
use app\models\Setting;
use app\modules\admin\models\IncidentReportForm;
use app\modules\incident\components\IncidentWording;
use app\modules\incident\models\Incident;
use tests\FunctionalTestCase;

/**
 * Admin › Settings › Incident reports over HTTP: the plain-English wording of
 * each status and severity (the PDF itself is covered by unit/IncidentReportTest).
 */
class IncidentReportSettingsTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::deleteAll();
        Setting::reset();
    }

    /** Every text as currently shown, with $changes applied */
    private function form(array $changes = []): array
    {
        return ['IncidentReportForm' => $changes + IncidentReportForm::current()->getAttributes()];
    }

    public function testAdminSeesTheDefaultWording(): void
    {
        $this->loginAs('admin');
        $this->get('/admin/settings');
        $this->assertOk();
        $this->assertSee('id="incident-report-form"');
        $this->assertSee('Incident reports: what each status means');
        $this->assertSee('Incident reports: what each severity means');
        $this->assertSee('name="IncidentReportForm[status_eradicated]"');
        $this->assertSee('name="IncidentReportForm[severity_critical]"');
        $this->assertSee(htmlspecialchars(IncidentWording::STATUS_DEFAULTS[Incident::STATUS_TRIAGE], ENT_QUOTES));
    }

    public function testSavingWordingIsStoredAndAudited(): void
    {
        $this->loginAs('admin');
        $this->post('/admin/settings', $this->form(['status_closed' => 'All done and reviewed by the board.', 'severity_low' => '']));
        $this->assertRedirect('/admin/settings#incident-reports');
        $this->assertSame('Incident report wording saved.', $this->flash('success'));
        $this->assertSame('All done and reviewed by the board.', IncidentWording::status(Incident::STATUS_CLOSED));
        $this->assertSame(IncidentWording::SEVERITY_DEFAULTS[Incident::SEVERITY_LOW], IncidentWording::severity(Incident::SEVERITY_LOW));

        $this->get('/admin/settings');
        $this->assertSee('All done and reviewed by the board.');
        $entry = AuditLog::find()->where(['entity_type' => 'setting', 'entity_label' => 'Incident report wording'])->one();
        $this->assertNotNull($entry);
        $this->get("/admin/audit/{$entry->id}");
        $this->assertOk();
        $this->assertSee('Status: Closed');

        // Branding and frameworks are untouched by this form
        $this->assertNull(Setting::get('app_name'));
        $this->assertNull(Setting::get('framework.soc2'));
    }

    public function testOverlongWordingIsShownAsAnError(): void
    {
        $this->loginAs('admin');
        $this->post('/admin/settings', $this->form(['severity_high' => str_repeat('x', IncidentReportForm::MAX_LENGTH + 1)]));
        $this->assertOk();
        $this->assertSee('High should contain at most');
        $this->assertSame([], Setting::values());
    }

    public function testOnlyAdminsCanChangeTheWording(): void
    {
        $this->loginAs('analyst');
        $this->post('/admin/settings', $this->form(['status_new' => 'Changed by an analyst']));
        $this->assertStatus(403);
        $this->assertNull(Setting::get('incident.status.new'));
    }
}
