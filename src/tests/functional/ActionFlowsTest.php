<?php

namespace tests\functional;

use app\modules\action\models\Action;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\intel\models\EdgeFinding;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;
use app\models\AuditLog;
use tests\FunctionalTestCase;

/**
 * Remediation actions against the demo data: the register, raising an action from a record,
 * closing it, the Actions tab on every record type and the My work page.
 */
class ActionFlowsTest extends FunctionalTestCase
{
    protected static bool $seed = true;

    public function testRegisterFiltersAndPermissions(): void
    {
        $this->loginAs('viewer');
        foreach (['/actions', '/actions?filter=mine', '/actions?filter=overdue', '/actions?filter=due-soon', '/actions?filter=closed',
                     '/actions?filter=all', '/actions?filter=archived', '/actions?record_type=none', '/actions?record_type=risk&priority=high&status=open',
                     '/actions?sort=-due_date', '/actions?sort=priority', '/actions?sort=-priority', '/actions?q=MFA', '/actions?filter=bogus'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/actions')->assertOk();
        $this->assertSee('Turn on MFA for the four remaining admin accounts');
        $this->assertNotSee('Block legacy authentication', 'done actions are not in the default (open) list');
        $this->get('/actions?filter=closed')->assertOk();
        $this->assertSee('Block legacy authentication');
        $this->assertSee('Replace the unsupported NAS');
        $this->get('/actions?filter=overdue')->assertOk();
        $this->assertSee('Close the public S3 bucket');
        $this->assertNotSee('Remove the default SNMP community');
        $this->get('/actions?record_type=none')->assertOk();
        $this->assertSee('Patch the VPN appliance');
        $this->assertNotSee('Close the public S3 bucket');
        $this->assertNotSee('New action');

        $action = Action::findOne(['title' => 'Close the public S3 bucket']);
        $this->get('/actions/' . $action->id)->assertOk();
        $this->assertSee('Overdue');
        $this->assertNotSee('Close this action', 'viewers cannot close');
        $this->get('/actions/create');
        $this->assertSame(403, $this->status);
        $this->post('/actions/' . $action->id . '/close', ['outcome' => 'x']);
        $this->assertSame(403, $this->status);
        $this->get('/search?q=' . $action->reference)->assertRedirect('/actions/' . $action->id);
    }

    public function testRaisingAnActionFromARecordAndClosingIt(): void
    {
        $risk = Risk::find()->where(['not', ['owner_id' => null]])->andWhere(['archived_at' => null])->one();
        $this->loginAs('analyst');
        $this->get('/actions/create?record_type=risk&record_id=' . $risk->id)->assertOk();
        $this->assertSee('value="risk:' . $risk->id . '" selected');
        $this->assertSee('<option value="' . $risk->owner_id . '" selected');

        $this->post('/actions/create', ['Action' => [
            'title' => 'Write the incident playbook for ransomware', 'owner_id' => $risk->owner_id, 'due_date' => date('Y-m-d', strtotime('+10 days')),
            'priority' => 'high', 'status' => 'open', 'recordKey' => 'risk:' . $risk->id, 'description' => 'Tabletop it with IT.',
        ]])->assertRedirect('/actions/');
        $action = Action::findOne(['title' => 'Write the incident playbook for ransomware']);
        $this->assertSame('risk', $action->record_type);
        $this->assertSame($risk->id, (int) $action->record_id);
        $this->assertMatchesRegularExpression('/^ACT-\d{4}$/', $action->reference);
        $this->assertTrue($action->isDueSoon());

        // The risk shows it on its Actions tab
        $this->get('/risks/' . $risk->id)->assertOk();
        $this->assertSee('Write the incident playbook for ransomware');
        $this->assertSee('Raise an action');

        // Closing needs the outcome
        $this->get('/actions/' . $action->id)->assertOk();
        $this->assertSee('Close this action');
        $this->post('/actions/' . $action->id . '/close', ['status' => 'done', 'outcome' => ''])->assertRedirect();
        $this->assertStringContainsString('Say what was done', (string) $this->flash('danger'));
        $this->assertTrue(Action::findOne($action->id)->isOpen());
        $this->post('/actions/' . $action->id . '/close', ['status' => 'done', 'outcome' => 'Playbook in the wiki, tested on 3 Nov.'])->assertRedirect();
        $done = Action::findOne($action->id);
        $this->assertSame(Action::STATUS_DONE, $done->status);
        $this->assertNotNull($done->completed_at);
        $this->assertSame($this->userIdFor('analyst'), (int) $done->completed_by);
        $this->get('/actions/' . $action->id)->assertOk();
        $this->assertSee('Playbook in the wiki');
        $this->assertNotSee('Close this action');
        $this->post('/actions/' . $action->id . '/close', ['status' => 'cancelled', 'outcome' => 'x'])->assertRedirect();
        $this->assertSame(Action::STATUS_DONE, Action::findOne($action->id)->status, 'a closed action is not closed again');

        // Reopening by editing clears the closure
        $this->post('/actions/' . $action->id . '/update', ['Action' => ['status' => 'in_progress']])->assertRedirect();
        $reopened = Action::findOne($action->id);
        $this->assertNull($reopened->completed_at);
        $this->assertNull($reopened->completed_by);

        // Cancelled with a reason
        $this->post('/actions/' . $action->id . '/close', ['status' => 'cancelled', 'outcome' => 'Covered by the group playbook.'])->assertRedirect();
        $this->assertSame(Action::STATUS_CANCELLED, Action::findOne($action->id)->status);

        $changes = AuditLog::find()->where(['entity_type' => 'action', 'entity_id' => $action->id])->count();
        $this->assertGreaterThanOrEqual(4, (int) $changes);
        $this->get('/actions/' . $action->id)->assertOk();
        $this->assertSee('Why it was cancelled');
    }

    public function testValidation(): void
    {
        $this->loginAs('analyst');
        $this->post('/actions/create', ['Action' => ['title' => '', 'owner_id' => '', 'status' => 'done', 'priority' => 'urgent', 'recordKey' => 'risk:999999']])->assertOk();
        $this->assertSee('Action cannot be blank.');
        $this->assertSee('Owner cannot be blank.');
        $this->assertSee('Say what was done');
        $this->assertSee('Priority is invalid.');
        $this->assertSee('That record does not exist.');
        $this->post('/actions/create', ['Action' => ['title' => 'X', 'owner_id' => $this->userIdFor('analyst'), 'status' => 'cancelled', 'priority' => 'low']])->assertOk();
        $this->assertSee('Say why the action was cancelled.');

        // A malformed record key is a standalone action
        $this->post('/actions/create', ['Action' => ['title' => 'Standalone', 'owner_id' => $this->userIdFor('analyst'), 'status' => 'open', 'priority' => 'low', 'recordKey' => 'nonsense']])->assertRedirect();
        $action = Action::findOne(['title' => 'Standalone']);
        $this->assertNull($action->record_type);
        $this->assertNull($action->record_id);
        $this->get('/actions/create?record_type=risk&record_id=999999')->assertOk();
        $this->get('/actions/create?record_type=nonsense&record_id=1')->assertOk();
    }

    public function testEveryRecordTypeHasAnActionsTab(): void
    {
        $this->loginAs('viewer');
        $routes = [
            Risk::class => '/risks/', Control::class => '/controls/', Decision::class => '/decisions/', Incident::class => '/incidents/',
            Device::class => '/devices/', CloudAsset::class => '/cloud/assets/', CloudAccount::class => '/cloud/accounts/',
            Supplier::class => '/suppliers/', EdgeFinding::class => '/intel/edge/',
        ];
        foreach ($routes as $class => $route) {
            $this->assertArrayHasKey($class::entityType(), Action::RECORD_CLASSES);
            $record = $class::find()->orderBy(['id' => SORT_ASC])->one();
            $this->get($route . $record->id)->assertOk();
            $this->assertSee('Actions (');
            $this->assertNotSee('Raise an action');
        }
        $this->loginAs('analyst');
        $incident = Action::findOne(['title' => 'Block legacy authentication in the identity provider'])->getRecord();
        $this->get('/incidents/' . $incident->id)->assertOk();
        $this->assertSee('Block legacy authentication in the identity provider');
        $this->assertSee('Raise an action');
    }

    public function testMyWork(): void
    {
        // The demo analyst owns the demo actions
        $demo = \app\models\User::findByUsername('analyst');
        $_SESSION = ['__id' => $demo->id, '__authKey' => $demo->auth_key];
        $this->get('/my-work')->assertOk();
        $this->assertSee('My work');
        $this->assertSee('Move backups to an immutable vault');
        $this->assertSee('Actions due in the next 14 days');
        $this->assertNotSee('Turn on MFA for the four remaining admin accounts', "jpatel's action");

        $this->loginAs('viewer');
        $this->get('/my-work')->assertOk();
        $this->assertSee('Nothing is overdue or due soon for you');

        $this->loginAs('admin');
        $this->get('/')->assertOk();
        $this->assertSee('Overdue actions');
        $this->get('/actions?filter=mine')->assertOk();
    }
}
