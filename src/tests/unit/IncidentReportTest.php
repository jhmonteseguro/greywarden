<?php

namespace tests\unit;

use app\components\RecordPdf;
use app\models\Attachment;
use app\models\AuditLog;
use app\models\Lookup;
use app\models\Setting;
use app\modules\admin\models\BrandingForm;
use app\modules\admin\models\IncidentReportForm;
use app\modules\control\models\Control;
use app\modules\incident\components\IncidentWording;
use app\modules\incident\models\Incident;
use app\modules\incident\models\IncidentTimeline;
use app\modules\inventory\models\Device;
use tests\DbTestCase;
use Yii;
use yii\base\UnknownPropertyException;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;
use yii\web\View;

/**
 * The incident PDF report: plain-English wording (components/IncidentWording),
 * the wording settings (Admin › Settings › Incident reports) and the document.
 */
class IncidentReportTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::deleteAll();
        Setting::reset();
    }

    private function incident(array $attrs = []): Incident
    {
        return new Incident($attrs + [
            'title' => 'Customer list emailed to the wrong client',
            'category_id' => $this->lookupId(Lookup::TYPE_INCIDENT_CATEGORY),
            'severity' => Incident::SEVERITY_HIGH,
            'status' => Incident::STATUS_INVESTIGATING,
            'detected_at' => '2026-10-01 10:00:00',
        ]);
    }

    private function saved(array $attrs = []): Incident
    {
        $incident = $this->incident($attrs);
        $this->assertTrue($incident->save(), json_encode($incident->getErrors()));
        return $incident;
    }

    private function html(Incident $incident): string
    {
        return RecordPdf::html(new View(), Yii::getAlias('@app/modules/incident/views/incident/pdf.php'), $incident, '28 Oct 2026 12:00', 'Test User');
    }

    // ---- Wording ------------------------------------------------------------------

    public function testEveryStatusAndSeverityHasADefaultMeaning(): void
    {
        $this->assertSame(array_keys(Incident::statusOptions()), array_keys(IncidentWording::STATUS_DEFAULTS));
        $this->assertSame(array_keys(Incident::severityOptions()), array_keys(IncidentWording::SEVERITY_DEFAULTS));
        $this->assertSame(IncidentWording::STATUS_DEFAULTS['contained'], IncidentWording::status('contained'));
        $this->assertSame(IncidentWording::SEVERITY_DEFAULTS['low'], IncidentWording::severity('low'));
        $this->assertSame('', IncidentWording::status('unknown'));
        $this->assertSame('incident.severity.high', IncidentWording::settingName('severity', 'high'));
    }

    public function testSavedWordingReplacesTheDefaultUntilCleared(): void
    {
        Setting::put(['incident.status.contained' => 'Under control.', 'incident.severity.low' => '   ']);
        $this->assertSame('Under control.', IncidentWording::status('contained'));
        $this->assertSame(IncidentWording::SEVERITY_DEFAULTS['low'], IncidentWording::severity('low'), 'blank = default');

        Setting::put(['incident.status.contained' => null]);
        $this->assertSame(IncidentWording::STATUS_DEFAULTS['contained'], IncidentWording::status('contained'));
    }

    public function testImpactsAreDescribedInPlainEnglish(): void
    {
        $this->assertSame([], IncidentWording::impacts($this->incident()));
        $texts = IncidentWording::impacts($this->incident(['impact_confidentiality' => true, 'impact_availability' => true]));
        $this->assertSame([IncidentWording::IMPACT_TEXT['impact_confidentiality'], IncidentWording::IMPACT_TEXT['impact_availability']], $texts);
    }

    public function testRegulatorPositionCoversEveryDeadlineState(): void
    {
        $fmt = Yii::$app->formatter;
        $deadline = $fmt->asDatetime('2026-10-04 10:00:00');
        $pii = ['personal_data_involved' => true];
        $required = $pii + ['regulator_notification_required' => true];

        $this->assertSame('No personal data is known to be involved.', IncidentWording::regulator($this->incident()));
        $this->assertStringContainsString('assessed as not needing to be reported', IncidentWording::regulator($this->incident($pii)));

        $pending = IncidentWording::regulator($this->incident($required), '2026-10-03 10:00:00');
        $this->assertStringContainsString("must be reported to the data protection regulator by $deadline (1d left)", $pending);

        $overdue = IncidentWording::regulator($this->incident($required), '2026-10-05 10:00:00');
        $this->assertStringContainsString("should have been reported to the data protection regulator by $deadline and has not been reported yet", $overdue);

        $met = IncidentWording::regulator($this->incident($required + ['regulator_notified_at' => '2026-10-02 09:00:00', 'regulator_reference' => 'ICO-123',
            'subjects_notified_at' => '2026-10-03 09:00:00']));
        $this->assertStringContainsString('reported to the data protection regulator on ' . $fmt->asDatetime('2026-10-02 09:00:00') . ' (reference ICO-123), within the 72-hour deadline.', $met);
        $this->assertStringContainsString('The people affected were told on ' . $fmt->asDatetime('2026-10-03 09:00:00') . '.', $met);

        $late = IncidentWording::regulator($this->incident($required + ['regulator_notified_at' => '2026-10-06 09:00:00']));
        $this->assertStringContainsString("after the 72-hour deadline of $deadline.", $late);
        $this->assertStringNotContainsString('reference', $late);
    }

    // ---- Settings form ----------------------------------------------------------------

    public function testFormShowsTheWordingInForce(): void
    {
        Setting::put(['incident.severity.critical' => 'Board-level incident.']);
        $form = IncidentReportForm::current();
        $this->assertSame('Board-level incident.', $form->severity_critical);
        $this->assertSame(IncidentWording::STATUS_DEFAULTS['new'], $form->status_new);
        $this->assertTrue(isset($form->status_new));
        $this->assertSame(['status_new', 'status_triage', 'status_investigating', 'status_contained', 'status_eradicated', 'status_recovered', 'status_closed'],
            IncidentReportForm::attributesOf('status'));
        $this->assertSame(['severity_critical', 'severity_high', 'severity_medium', 'severity_low'], IncidentReportForm::attributesOf('severity'));
        $this->assertSame('Contained', $form->getAttributeLabel('status_contained'));
        $this->assertSame('Severity: Critical', BrandingForm::instance()->getAttributeLabel('severity_critical'), 'audit entries are labelled by kind');
    }

    public function testUnknownPropertiesStillFail(): void
    {
        $form = new IncidentReportForm();
        $this->assertFalse(isset($form->nonsense));
        $this->expectException(UnknownPropertyException::class);
        $form->nonsense = 'x';
    }

    public function testReadingAnUnknownPropertyFails(): void
    {
        $this->expectException(UnknownPropertyException::class);
        (new IncidentReportForm())->nonsense;
    }

    public function testSaveStoresOnlyChangedWordingAndAuditsIt(): void
    {
        $form = IncidentReportForm::current();
        $form->status_contained = '  Under control; clean-up under way.  ';
        $form->severity_low = '';                                   // blank = keep the default
        $this->assertTrue($form->save());

        $this->assertSame('Under control; clean-up under way.', IncidentWording::status('contained'));
        $this->assertSame(['incident.status.contained'], array_keys(Setting::values()), 'untouched wording is not stored');
        $log = AuditLog::find()->where(['entity_type' => 'setting', 'entity_label' => 'Incident report wording'])->one();
        $this->assertNotNull($log);
        $this->assertSame([IncidentWording::STATUS_DEFAULTS['contained'], 'Under control; clean-up under way.'], $log->getChangesArray()['status_contained']);

        // Back to the default: stored empty so later default wording applies; nothing changed = no audit entry
        $form = IncidentReportForm::current();
        $form->status_contained = IncidentWording::STATUS_DEFAULTS['contained'];
        $this->assertTrue($form->save());
        $this->assertSame('', Setting::get('incident.status.contained'));
        $this->assertTrue(IncidentReportForm::current()->save());
        $this->assertSame(2, (int) AuditLog::find()->where(['entity_type' => 'setting', 'entity_label' => 'Incident report wording'])->count());
    }

    public function testOverlongWordingIsRejected(): void
    {
        $form = IncidentReportForm::current();
        $form->severity_high = str_repeat('x', IncidentReportForm::MAX_LENGTH + 1);
        $this->assertFalse($form->save());
        $this->assertTrue($form->hasErrors('severity_high'));
        $this->assertSame([], Setting::values());
    }

    // ---- Document ------------------------------------------------------------------------

    public function testInterimReportWhileOpen(): void
    {
        Setting::put(['incident.status.investigating' => 'We are <still> looking into it.']);
        $incident = $this->saved(['description' => 'Technical account only.', 'personal_data_involved' => true, 'regulator_notification_required' => true]);
        $html = $this->html($incident);

        $this->assertStringContainsString('Interim incident report', $html);
        $this->assertStringContainsString('<strong>Interim report</strong>', $html);
        $this->assertStringContainsString('We are &lt;still&gt; looking into it.', $html, 'wording from the settings, encoded');
        $this->assertStringContainsString(IncidentWording::SEVERITY_DEFAULTS['high'], $html);
        $this->assertStringContainsString('Technical account only.', $html, 'description is the summary when there is no management summary');
        $this->assertStringNotContainsString('Technical description', $html);
        $this->assertStringContainsString('Not assessed yet.', $html);
        $this->assertStringContainsString('should have been reported to the data protection regulator', $html);
        $this->assertStringContainsString('Not determined yet: the investigation is continuing.', $html);
        $this->assertStringContainsString('No controls have been linked to this incident yet.', $html);
        $this->assertStringContainsString('<h3>Regulator</h3>', $html);
        $this->assertStringContainsString('Not yet', $html);
        $this->assertStringContainsString('Incident logged with status Investigating, severity High.', $html, 'timeline included');
        $this->assertStringStartsWith('%PDF-', RecordPdf::render(new View(), Yii::getAlias('@app/modules/incident/views/incident/pdf.php'), $incident));
    }

    public function testFinalReportOnceClosed(): void
    {
        $control = new Control(['title' => 'Email data loss prevention', 'status' => Control::STATUS_PLANNED, 'control_type' => 'preventive', 'nature' => 'technical']);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        $incident = $this->saved([
            'status' => Incident::STATUS_CLOSED, 'occurred_at' => '2026-10-01 08:00:00', 'contained_at' => '2026-10-01 12:00:00', 'closed_at' => '2026-10-03 10:00:00',
            'management_summary' => 'A <spreadsheet> went to the wrong client.', 'description' => 'Autocomplete picked an external address.',
            'impact_confidentiality' => true, 'root_cause' => 'No DLP warning.', 'responseControlIds' => [$control->id],
        ]);
        IncidentTimeline::add($incident->id, IncidentTimeline::TYPE_COMMUNICATION, 'Recipient asked to delete it.', '2026-10-01 11:00:00');
        IncidentTimeline::add($incident->id, IncidentTimeline::TYPE_ACTION, "Mail recalled.\nDLP rule drafted.", '2026-10-01 10:30:00');
        $html = $this->html($incident);

        $this->assertStringContainsString('Final incident report', $html);
        $this->assertStringContainsString('This incident was closed on ' . Yii::$app->formatter->asDatetime('2026-10-03 10:00:00'), $html);
        $this->assertStringContainsString('A &lt;spreadsheet&gt; went to the wrong client.', $html);
        $summary = strpos($html, 'A &lt;spreadsheet&gt;');
        $technical = strpos($html, 'Technical description');
        $this->assertNotFalse($technical);
        $this->assertGreaterThan($summary, $technical, 'description moves to the technical appendix');
        $this->assertGreaterThan(strpos($html, 'class="appendix"'), strpos($html, 'Autocomplete picked an external address.'));
        $this->assertStringContainsString(IncidentWording::IMPACT_TEXT['impact_confidentiality'], $html);
        $this->assertStringContainsString('No personal data is known to be involved.', $html);
        $this->assertStringContainsString('No DLP warning.', $html);
        $this->assertStringNotContainsString('To be completed when the incident is reviewed.', $html);
        $this->assertStringContainsString('What is being added or improved', $html);
        $this->assertStringContainsString($control->reference, $html);
        $this->assertStringContainsString('2d', $html, 'time to close');
        $this->assertStringNotContainsString('<h3>Regulator</h3>', $html);
        // Full timeline, oldest first
        $this->assertLessThan(strpos($html, 'Recipient asked to delete it.'), strpos($html, "Mail recalled.<br />\nDLP rule drafted."));
    }

    public function testTimelineEntriesNameTheirFilesEvenOnceRemoved(): void
    {
        $incident = $this->saved();
        $path = Yii::$app->params['attachments.path'];
        Yii::$app->params['attachments.path'] = $dir = sys_get_temp_dir() . '/gw-timeline-' . bin2hex(random_bytes(4));
        try {
            $tmp = tempnam(sys_get_temp_dir(), 'gwtl');
            file_put_contents($tmp, "user,ip\n");
            $file = Attachment::attach($incident, new UploadedFile(['name' => 'signins.csv', 'tempName' => $tmp, 'type' => 'text/csv', 'size' => 8, 'error' => UPLOAD_ERR_OK]));
            unlink($tmp);
            $this->assertInstanceOf(Attachment::class, $file);
            $entry = IncidentTimeline::add($incident->id, IncidentTimeline::TYPE_EVIDENCE, 'Sign-in logs exported', '2026-10-01 10:30:00');
            $entry->linkFile($file);
            $this->assertTrue($entry->save(false));
            $plain = IncidentTimeline::add($incident->id, IncidentTimeline::TYPE_NOTE, 'No file here', '2026-10-01 10:40:00');
            $this->assertNull($plain->getFileName());
            $this->assertFalse($plain->getIsFileRemoved());

            $html = $this->html($incident);
            $this->assertStringContainsString('File: signins.csv</div>', $html);

            $this->assertTrue($file->remove());
            $entry = IncidentTimeline::findOne($entry->id);
            $this->assertNull($entry->attachment_id);
            $this->assertSame('signins.csv', $entry->getFileName());
            $this->assertTrue($entry->getIsFileRemoved());
            $this->assertStringContainsString('File: signins.csv (since removed)', $this->html($incident));
        } finally {
            Yii::$app->params['attachments.path'] = $path;
            FileHelper::removeDirectory($dir);
        }
    }

    public function testLongLinkListsAreCutShort(): void
    {
        $ids = [];
        for ($i = 1; $i <= 26; $i++) {
            $device = new Device(['hostname' => sprintf('lt-report-%03d', $i), 'device_type' => 'laptop', 'os' => 'Ubuntu', 'os_version' => '24.04 LTS',
                'status' => 'active', 'criticality' => 'medium', 'data_classification' => 'internal']);
            $this->assertTrue($device->save(), json_encode($device->getErrors()));
            $ids[] = $device->id;
        }
        $html = $this->html($this->saved(['deviceIds' => $ids]));
        $this->assertStringContainsString('lt-report-001', $html);
        $this->assertStringNotContainsString('lt-report-026', $html);
        $this->assertStringContainsString('And 1 more; the register lists them all.', $html);
    }
}
