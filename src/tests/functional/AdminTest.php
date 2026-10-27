<?php

namespace tests\functional;

use app\components\Rbac;
use app\models\AuditLog;
use app\models\Lookup;
use app\models\User;
use tests\FunctionalTestCase;
use yii\db\Query;

/**
 * Admin module: user management, lookup lists and the global audit log.
 */
class AdminTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('admin');
    }

    private function userForm(array $attrs): array
    {
        return ['UserForm' => $attrs + [
            'full_name' => 'Admin Test User', 'username' => 'admin.test', 'email' => 'admin.test@example.com', 'job_title' => '',
            'role' => Rbac::ROLE_VIEWER, 'status' => User::STATUS_ACTIVE, 'password' => 'Admin!Test-12345', 'must_change_password' => 0,
        ]];
    }

    public function testCreateAndUpdateUser(): void
    {
        $this->post('/admin/user/create', $this->userForm(['username' => 'bad name', 'password' => 'short', 'role' => 'root']));
        $this->assertOk();
        $this->assertSee('Use letters, numbers, dots, dashes or underscores.');
        $this->assertSee('at least 10 characters');
        $this->assertSee('Role is invalid');

        $this->post('/admin/user/create', $this->userForm(['password' => '']));
        $this->assertSee('Initial password cannot be blank');

        $this->post('/admin/user/create', $this->userForm([]));
        $this->assertRedirect('/admin/user/');
        $user = User::findByUsername('admin.test');
        $this->assertSame(Rbac::ROLE_VIEWER, $user->getRoleName());
        $this->assertTrue($user->validatePassword('Admin!Test-12345'));
        $this->assertSame('User admin.test created.', $this->flash('success'));

        $this->post('/admin/user/create', $this->userForm([]));
        $this->assertSee('has already been taken');

        // Promote, rename; the old password is kept
        $this->post("/admin/user/{$user->id}/update", $this->userForm(['role' => Rbac::ROLE_ANALYST, 'full_name' => 'Promoted User', 'password' => '']));
        $this->assertRedirect("/admin/user/{$user->id}");
        $user->refresh();
        $this->assertSame('Promoted User', $user->full_name);
        $this->assertSame(Rbac::ROLE_ANALYST, $user->getRoleName());
        $this->assertTrue($user->validatePassword('Admin!Test-12345'));
        $roleChange = AuditLog::find()->where(['entity_type' => 'user', 'entity_id' => $user->id, 'action' => 'update'])->all();
        $this->assertContains(['viewer', 'analyst'], array_map(static fn($l) => $l->getChangesArray()['role'] ?? null, $roleChange));

        // Disabling through the form rotates the auth key (ends sessions)
        $key = $user->auth_key;
        $this->post("/admin/user/{$user->id}/update", $this->userForm(['role' => Rbac::ROLE_ANALYST, 'status' => User::STATUS_DISABLED, 'password' => '']));
        $this->assertRedirect();
        $user->refresh();
        $this->assertFalse($user->isActive());
        $this->assertNotSame($key, $user->auth_key);

        $this->get('/admin/user?q=admin.test&status=0&role=analyst');
        $this->assertOk();
        $this->assertSee('admin.test');
        $this->get('/admin/user?status=abc');
        $this->assertOk();
    }

    public function testAdminCannotDemoteOrDisableThemselves(): void
    {
        $me = $this->userIdFor('admin');
        $this->post("/admin/user/$me/update", $this->userForm(['username' => 'fn.admin', 'email' => 'fn.admin@example.com', 'role' => Rbac::ROLE_VIEWER, 'status' => User::STATUS_DISABLED]));
        $this->assertOk();
        $this->assertSee('You cannot remove your own admin role.');
        $this->assertSee('You cannot disable your own account.');

        $this->post("/admin/user/$me/toggle-status");
        $this->assertRedirect();
        $this->assertSame('You cannot disable your own account.', $this->flash('danger'));
        $this->assertTrue(User::findOne($me)->isActive());
    }

    public function testToggleStatusAndResetPassword(): void
    {
        $viewer = $this->userIdFor('viewer');
        $this->post("/admin/user/$viewer/toggle-status");
        $this->assertRedirect();
        $this->assertFalse(User::findOne($viewer)->isActive());
        $this->post("/admin/user/$viewer/toggle-status");
        $this->assertTrue(User::findOne($viewer)->isActive());

        $this->post("/admin/user/$viewer/reset-password", ['ResetPasswordForm' => ['password' => 'weak', 'must_change_password' => 1]]);
        $this->assertOk();
        $this->assertSee('at least 10 characters');

        $this->post("/admin/user/$viewer/reset-password", ['ResetPasswordForm' => ['password' => 'Reset!Viewer-9876', 'must_change_password' => 1]]);
        $this->assertRedirect("/admin/user/$viewer");
        $user = User::findOne($viewer);
        $this->assertTrue($user->validatePassword('Reset!Viewer-9876'));
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertTrue(AuditLog::find()->where(['entity_type' => 'user', 'entity_id' => $viewer, 'action' => 'password_reset'])->exists());
    }

    public function testUnlockClearsFailedAttemptsAndIsAudited(): void
    {
        $viewer = $this->userIdFor('viewer');
        for ($i = 0; $i < 3; $i++) {
            \Yii::$app->db->createCommand()->insert('login_attempt', ['username' => 'fn.viewer', 'ip' => '192.0.2.10', 'success' => 0, 'attempted_at' => date('Y-m-d H:i:s')])->execute();
        }
        $this->get("/admin/user/$viewer");
        $this->assertSee('192.0.2.10');
        $this->post("/admin/user/$viewer/unlock");
        $this->assertRedirect();
        $this->assertFalse((new Query())->from('login_attempt')->where(['username' => 'fn.viewer', 'success' => 0])->exists());
        $entry = AuditLog::find()->where(['entity_type' => 'user', 'entity_id' => $viewer, 'action' => 'unlock'])->one();
        $this->assertSame([3, 0], $entry->getChangesArray()['failed_attempts_cleared']);
    }

    public function testLookupLifecycle(): void
    {
        $this->get('/admin/lookup?type=location');
        $this->assertOk();

        $this->post('/admin/lookup/create?type=location', ['Lookup' => ['type' => 'location', 'name' => '', 'sort_order' => -1]]);
        $this->assertOk();
        $this->assertSee('Name cannot be blank');

        $this->post('/admin/lookup/create?type=location', ['Lookup' => ['type' => 'location', 'name' => 'Test Site', 'sort_order' => 999, 'is_active' => 1]]);
        $this->assertRedirect('type=location');
        $lookup = Lookup::findOne(['type' => 'location', 'name' => 'Test Site']);
        $this->assertNotNull($lookup);

        $this->post('/admin/lookup/create?type=location', ['Lookup' => ['type' => 'location', 'name' => 'Test Site']]);
        $this->assertSee('This name already exists for this list.');

        // The list a value belongs to cannot be changed
        $this->post("/admin/lookup/{$lookup->id}/update", ['Lookup' => ['type' => 'department', 'name' => 'Test Site (renamed)']]);
        $this->assertRedirect();
        $lookup->refresh();
        $this->assertSame('location', $lookup->type);
        $this->assertSame('Test Site (renamed)', $lookup->name);

        $this->get("/admin/lookup/{$lookup->id}");
        $this->assertRedirect("/admin/lookup/{$lookup->id}/update");
        $this->get("/admin/lookup/{$lookup->id}/update");
        $this->assertOk();

        $this->post("/admin/lookup/{$lookup->id}/toggle");
        $this->assertFalse((bool) Lookup::findOne($lookup->id)->is_active);
        $this->assertStringContainsString('deactivated', $this->flash('success'));
        $this->assertStringContainsString('(inactive)', Lookup::options('location', $lookup->id)[$lookup->id]);
        $this->assertArrayNotHasKey($lookup->id, Lookup::options('location'));

        $this->post("/admin/lookup/{$lookup->id}/delete");
        $this->assertRedirect();
        $this->assertNull(Lookup::findOne($lookup->id));
        $this->assertTrue(AuditLog::find()->where(['entity_type' => 'lookup', 'entity_id' => $lookup->id, 'action' => 'delete'])->exists());
    }

    public function testLookupInUseCannotBeDeleted(): void
    {
        $category = $this->lookupId(Lookup::TYPE_RISK_CATEGORY);
        $risk = new \app\modules\risk\models\Risk(['title' => 'Uses the category', 'category_id' => $category, 'owner_id' => $this->userIdFor('admin'),
            'status' => 'identified', 'date_identified' => '2026-01-01', 'inherent_likelihood' => 2, 'inherent_impact' => 2]);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));

        $this->post("/admin/lookup/$category/delete");
        $this->assertRedirect();
        $this->assertStringContainsString('deactivate it instead', $this->flash('warning'));
        $this->assertNotNull(Lookup::findOne($category));
        $this->assertGreaterThanOrEqual(1, Lookup::findOne($category)->getUsageCount());
    }

    public function testAuditLogFiltersAndEntryView(): void
    {
        AuditLog::record('risk', 1, 'RSK-0001 Test', 'update', ['status' => ['identified', 'assessed']]);
        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();

        foreach (['/admin/audit?entity_type=risk&action=update&q=RSK', '/admin/audit?date_from=2026-01-01&date_to=2099-12-31&ip=127',
                     '/admin/audit?user_id=' . $this->userIdFor('admin'), '/admin/audit?sort=-entity_type'] as $url) {
            $this->get($url);
            $this->assertOk();
        }
        $this->get('/admin/audit?entity_type=risk');
        $this->assertSee('RSK-0001 Test');
        // Invalid filter: no rows rather than an error
        $this->get('/admin/audit?date_from=yesterday&q=RSK-0001');
        $this->assertOk();
        $this->assertNotSee('RSK-0001 Test');

        $this->get("/admin/audit/{$entry->id}");
        $this->assertOk();
        $this->assertSee('Assessed');
    }
}
