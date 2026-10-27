<?php

namespace tests\unit;

use app\models\Lookup;
use app\modules\incident\components\IncidentMetrics;
use app\modules\incident\models\Incident;
use app\modules\incident\models\IncidentTimeline;
use tests\DbTestCase;

class IncidentModelTest extends DbTestCase
{
    private function makeIncident(array $attrs = []): Incident
    {
        return new Incident($attrs + [
            'title' => 'Test incident',
            'category_id' => $this->lookupId(Lookup::TYPE_INCIDENT_CATEGORY),
            'severity' => Incident::SEVERITY_HIGH,
            'status' => Incident::STATUS_NEW,
            'detected_at' => '2026-03-02T09:15',
        ]);
    }

    public function testDatetimeLocalInputIsNormalised(): void
    {
        $incident = $this->makeIncident();
        $this->assertTrue($incident->validate(), json_encode($incident->getErrors()));
        $this->assertSame('2026-03-02 09:15:00', $incident->detected_at);
    }

    public function testRegulatorFieldsClearedWithoutPersonalData(): void
    {
        $incident = $this->makeIncident([
            'personal_data_involved' => 0,
            'regulator_notification_required' => 1,
            'regulator_notified_at' => '2026-03-03 10:00',
        ]);
        $incident->validate();
        $this->assertFalse((bool) $incident->regulator_notification_required);
        $this->assertNull($incident->regulator_notified_at);
        $this->assertSame(IncidentMetrics::STATE_NOT_REQUIRED, $incident->getRegulatorDeadlineState());
    }

    public function testRegulatorDeadlineFromModel(): void
    {
        $incident = $this->makeIncident(['personal_data_involved' => 1, 'regulator_notification_required' => 1]);
        $incident->validate();
        $this->assertSame('2026-03-05 09:15:00', $incident->getRegulatorDeadline()->format('Y-m-d H:i:s'));
        $this->assertSame(IncidentMetrics::STATE_OVERDUE, $incident->getRegulatorDeadlineState('2026-03-06 00:00:00'));
        $this->assertSame(IncidentMetrics::STATE_PENDING, $incident->getRegulatorDeadlineState('2026-03-03 00:00:00'));
    }

    public function testChronologyIsValidated(): void
    {
        $incident = $this->makeIncident(['occurred_at' => '2026-03-03 00:00']);
        $this->assertFalse($incident->validate());
        $this->assertArrayHasKey('detected_at', $incident->getErrors());
    }

    public function testStatusChangeAddsTimelineEntryAndStampsContainment(): void
    {
        $incident = $this->makeIncident();
        $this->assertTrue($incident->save(), json_encode($incident->getErrors()));
        $this->assertMatchesRegularExpression('/^INC-\d{4,}$/', $incident->reference);

        $incident->status = Incident::STATUS_CONTAINED;
        $this->assertTrue($incident->save(), json_encode($incident->getErrors()));
        $this->assertNotNull($incident->contained_at, 'contained_at is stamped automatically');

        $bodies = IncidentTimeline::find()->select('body')->where(['incident_id' => $incident->id])->orderBy('id')->column();
        $this->assertCount(2, $bodies);
        $this->assertStringContainsString('logged with status New', $bodies[0]);
        $this->assertSame('Status changed from New to Contained.', $bodies[1]);
        $this->assertNotNull($incident->getTimeToContain());
    }

    public function testRootCauseRequiredToCloseHighSeverity(): void
    {
        $incident = $this->makeIncident(['status' => Incident::STATUS_CLOSED]);
        $this->assertFalse($incident->validate());
        $this->assertArrayHasKey('root_cause', $incident->getErrors());
        $incident->root_cause = 'Phishing';
        $this->assertTrue($incident->validate(), json_encode($incident->getErrors()));
        $this->assertNotNull($incident->closed_at);
    }
}
