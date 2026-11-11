<?php

namespace tests\unit;

use app\components\Actor;
use app\components\MyWork;
use app\components\Rbac;
use app\models\Lookup;
use app\models\User;
use app\modules\action\models\Action;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use tests\DbTestCase;

class ActionTest extends DbTestCase
{
    private function user(): User
    {
        return User::createUser('act.' . bin2hex(random_bytes(3)), bin2hex(random_bytes(3)) . '@example.com', 'Action Owner', 'Act!Test-Password1', Rbac::ROLE_ANALYST, false);
    }

    private function action(array $attributes): Action
    {
        $action = new Action($attributes + ['title' => 'Do the thing', 'status' => Action::STATUS_OPEN, 'priority' => Action::PRIORITY_MEDIUM, 'owner_id' => $this->userId()]);
        $this->assertTrue($action->save(), json_encode($action->getErrors()));
        return $action;
    }

    private function risk(int $ownerId): Risk
    {
        $risk = new Risk(['title' => 'Action test risk', 'status' => Risk::STATUS_IDENTIFIED, 'inherent_likelihood' => 3, 'inherent_impact' => 3,
            'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY), 'owner_id' => $ownerId, 'date_identified' => '2026-01-01']);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        return $risk;
    }

    public function testDueStates(): void
    {
        $today = '2026-11-09';
        $this->assertTrue((new Action(['status' => 'open', 'due_date' => '2026-11-08']))->isOverdue($today));
        $this->assertFalse((new Action(['status' => 'done', 'due_date' => '2026-11-08']))->isOverdue($today), 'closed actions are never overdue');
        $this->assertFalse((new Action(['status' => 'open', 'due_date' => null]))->isOverdue($today));
        $this->assertTrue((new Action(['status' => 'in_progress', 'due_date' => '2026-11-09']))->isDueSoon($today), 'due today');
        $this->assertTrue((new Action(['status' => 'open', 'due_date' => '2026-11-23']))->isDueSoon($today));
        $this->assertFalse((new Action(['status' => 'open', 'due_date' => '2026-11-24']))->isDueSoon($today));
        $this->assertFalse((new Action(['status' => 'open', 'due_date' => '2026-11-08']))->isDueSoon($today), 'overdue is not due soon');
        $this->assertSame('2026-11-23', Action::dueSoonDate($today));
        $this->assertSame('Open', (new Action(['status' => 'open']))->getStatusLabel());
        $this->assertSame('High', (new Action(['priority' => 'high']))->getPriorityLabel());
        $this->assertSame('weird', (new Action(['priority' => 'weird']))->getPriorityLabel());
    }

    public function testClosingStampsWhoAndWhen(): void
    {
        Actor::override($this->userId(), 'tester');
        $action = $this->action(['title' => 'Close me']);
        $this->assertNull($action->completed_at);
        $action->status = Action::STATUS_DONE;
        $action->outcome = 'Done and dusted.';
        $this->assertTrue($action->save());
        $this->assertNotNull($action->completed_at);
        $this->assertSame($this->userId(), (int) $action->completed_by);
        $stamp = $action->completed_at;
        $action->description = 'Edited later';
        $action->save();
        $this->assertSame($stamp, $action->completed_at, 'editing a closed action keeps when it closed');
        $action->status = Action::STATUS_OPEN;
        $action->save();
        $this->assertNull($action->completed_at);
        Actor::override(null);
    }

    public function testRaisedFromARecord(): void
    {
        $owner = $this->user();
        $risk = $this->risk($owner->id);
        $action = new Action();
        $action->raiseFrom($risk);
        $this->assertSame('risk:' . $risk->id, $action->getRecordKey());
        $this->assertSame($owner->id, $action->owner_id, "the risk's owner");
        $action->title = 'Fix it';
        $action->status = 'open';
        $action->priority = 'medium';
        $this->assertTrue($action->save(), json_encode($action->getErrors()));
        $this->assertSame($risk->id, $action->getRecord()->id);
        $this->assertSame($risk->reference, $action->formatAuditValue('record_id', $risk->id));
        $this->assertSame('#999999', $action->formatAuditValue('record_id', 999999));
        $this->assertSame('Risk', $action->formatAuditValue('record_type', 'risk'));
        $this->assertSame('Action Owner', $action->formatAuditValue('owner_id', $owner->id));
        $this->assertSame(1, Action::countOpenFor($risk));
        $this->assertArrayHasKey('Risk', $action->recordOptions());
        $this->assertArrayHasKey('risk:' . $risk->id, $action->recordOptions()['Risk']);

        // An incident's severity sets the priority; its assignee is the owner
        $incident = new Incident(['title' => 'Action test incident', 'severity' => Incident::SEVERITY_CRITICAL, 'status' => 'new',
            'detected_at' => '2026-11-01 09:00:00', 'category_id' => $this->lookupId(Lookup::TYPE_INCIDENT_CATEGORY), 'assigned_to_id' => $owner->id]);
        $this->assertTrue($incident->save(false));
        $fromIncident = new Action(['priority' => 'low']);
        $fromIncident->raiseFrom($incident);
        $this->assertSame(Action::PRIORITY_HIGH, $fromIncident->priority);
        $this->assertSame($owner->id, $fromIncident->owner_id);

        $fromIncident->setRecordKey('');
        $this->assertNull($fromIncident->record_type);
        $this->assertSame('', $fromIncident->getRecordKey());
        $this->assertNull($fromIncident->getRecord());
        $this->assertFalse(Action::takesActions('threat-story'));
        $this->assertTrue(Action::takesActions('edge-finding'));
    }

    public function testRecordHardDeletedAfterwards(): void
    {
        $risk = $this->risk($this->userId());
        $action = $this->action(['record_type' => 'risk', 'record_id' => $risk->id]);
        $this->assertFalse($action->isRecordDeleted());
        $riskId = $risk->id;
        $risk->delete();

        $action = Action::findOne($action->id);
        $this->assertNull($action->getRecord());
        $this->assertTrue($action->isRecordDeleted());
        $this->assertSame('Risk #' . $riskId . ' (deleted)', $action->getDeletedRecordLabel());
        $this->assertSame('Risk #' . $riskId . ' (deleted)', $action->recordOptions()['Risk']['risk:' . $riskId], 'kept in the form');

        // The action can still be edited and closed, and keeps its link
        $action->status = Action::STATUS_DONE;
        $action->outcome = 'Fixed before the risk was removed';
        $this->assertTrue($action->save(), json_encode($action->getErrors()));
        $this->assertSame($riskId, (int) Action::findOne($action->id)->record_id);

        // ...but cannot be moved to a record that does not exist
        $action->record_id = $riskId + 100000;
        $this->assertFalse($action->validate(['record_id']));
        $fresh = new Action(['title' => 'x', 'status' => 'open', 'priority' => 'low', 'owner_id' => $this->userId(), 'record_type' => 'risk', 'record_id' => $riskId]);
        $this->assertFalse($fresh->validate(['record_id']), 'a new action needs a live record');
    }

    public function testQueriesAndMyWork(): void
    {
        $me = $this->user();
        $today = date('Y-m-d');
        $overdue = $this->action(['title' => 'Overdue', 'owner_id' => $me->id, 'due_date' => date('Y-m-d', strtotime('-3 days')), 'priority' => 'low']);
        $soonHigh = $this->action(['title' => 'Soon high', 'owner_id' => $me->id, 'due_date' => date('Y-m-d', strtotime('+5 days')), 'priority' => 'high']);
        $soonCritical = $this->action(['title' => 'Soon critical', 'owner_id' => $me->id, 'due_date' => date('Y-m-d', strtotime('+5 days')), 'priority' => 'critical']);
        $later = $this->action(['title' => 'Later', 'owner_id' => $me->id, 'due_date' => date('Y-m-d', strtotime('+60 days'))]);
        $undated = $this->action(['title' => 'Undated', 'owner_id' => $me->id]);
        $this->action(['title' => 'Done', 'owner_id' => $me->id, 'due_date' => date('Y-m-d', strtotime('-9 days')), 'status' => 'done', 'outcome' => 'ok']);

        $ids = static fn($q) => array_map(static fn(Action $a) => $a->id, $q->all());
        $this->assertSame([$overdue->id], $ids(Action::find()->ownedBy($me->id)->overdue($today)));
        $this->assertSame([$soonCritical->id, $soonHigh->id], $ids(Action::find()->ownedBy($me->id)->dueSoon($today)->byUrgency()));
        $this->assertSame([$overdue->id, $soonCritical->id, $soonHigh->id, $later->id, $undated->id], $ids(Action::find()->ownedBy($me->id)->open()->byUrgency()));
        $this->assertCount(1, $ids(Action::find()->ownedBy($me->id)->closed()));
        $this->assertSame([], $ids(Action::find()->ownedBy(null)->andWhere(['title' => 'Overdue'])));

        $groups = MyWork::groups($me, $today);
        $this->assertSame(['actions-overdue', 'actions-due'], array_column($groups, 'key'));
        $this->assertSame(1, MyWork::urgentCount($groups));
        $this->assertSame(2, MyWork::laterActions($me, $today));
        $this->assertSame('Due ' . \Yii::$app->formatter->asDate($overdue->due_date) . ' · Low', call_user_func($groups[0]['meta'], $overdue));

        // Records the user owns
        $risk = $this->risk($me->id);
        Risk::updateAll(['next_review_date' => date('Y-m-d', strtotime('-1 day'))], ['id' => $risk->id]);
        $this->assertContains('risk-review', array_column(MyWork::groups($me, $today), 'key'));
        $this->assertSame([], MyWork::groups($this->user(), $today), 'nothing for someone else');
    }
}
