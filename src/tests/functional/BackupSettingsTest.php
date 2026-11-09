<?php

namespace tests\functional;

use app\models\AuditLog;
use app\models\Setting;
use app\modules\admin\models\BackupForm;
use tests\FunctionalTestCase;

/**
 * Admin › Settings › Backups over HTTP: the directory scripts/backup.sh writes
 * to (the script itself is covered by scripts/tests/backup-test.sh).
 */
class BackupSettingsTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::deleteAll();
        Setting::reset();
    }

    private function form(string $path): array
    {
        return ['BackupForm' => ['backup_path' => $path]];
    }

    public function testAdminSeesTheDefault(): void
    {
        $this->loginAs('admin');
        $this->get('/admin/settings');
        $this->assertOk();
        $this->assertSee('id="backup-form"');
        $this->assertSee('<legend class="gw-fieldset__title">Backups</legend>');
        $this->assertSee('name="BackupForm[backup_path]" value="" maxlength="1024" placeholder="backups"');
        $this->assertSee('Leave empty for the default, backups/.');
    }

    public function testPathWithSpacesIsStoredAndAudited(): void
    {
        $this->loginAs('admin');
        $this->post('/admin/settings', $this->form('  /mnt/nas share/Greywarden backups  '));
        $this->assertRedirect('/admin/settings#backups');
        $this->assertSame('Backup settings saved.', $this->flash('success'));
        $this->assertSame('/mnt/nas share/Greywarden backups', Setting::get(BackupForm::SETTING_PATH), 'ends trimmed, inner spaces kept');

        $this->get('/admin/settings');
        $this->assertSee('value="/mnt/nas share/Greywarden backups"');
        $entry = AuditLog::find()->where(['entity_type' => 'setting', 'entity_label' => 'Backups'])->one();
        $this->assertNotNull($entry);
        $this->get("/admin/audit/{$entry->id}");
        $this->assertOk();
        $this->assertSee('/mnt/nas share/Greywarden backups');

        // Saving the same value logs nothing; clearing it goes back to the default
        $count = (int) AuditLog::find()->where(['entity_label' => 'Backups'])->count();
        $this->post('/admin/settings', $this->form('/mnt/nas share/Greywarden backups'));
        $this->assertRedirect('/admin/settings#backups');
        $this->assertSame($count, (int) AuditLog::find()->where(['entity_label' => 'Backups'])->count());
        $this->post('/admin/settings', $this->form(''));
        $this->assertRedirect('/admin/settings#backups');
        Setting::reset();
        $this->assertSame('', BackupForm::path());
        $this->assertSame('', BackupForm::current()->backup_path);

        // Branding is untouched by this form
        $this->assertNull(Setting::get('app_name'));
    }

    public function testInvalidPathsAreShownAsErrors(): void
    {
        $this->loginAs('admin');
        foreach (["/srv/backups\n/etc" => 'cannot contain control characters', "/srv/\tbackups" => 'cannot contain control characters',
                  '/' => 'cannot be the root directory', '///' => 'cannot be the root directory',
                  str_repeat('a', BackupForm::MAX_LENGTH + 1) => 'should contain at most 1,024 characters'] as $path => $error) {
            $this->post('/admin/settings', $this->form($path));
            $this->assertOk();
            $this->assertSee('Backup directory ' . $error);
        }
        $this->assertNull(Setting::get(BackupForm::SETTING_PATH));
    }

    public function testOnlyAdminsCanChangeThePath(): void
    {
        $this->loginAs('analyst');
        $this->post('/admin/settings', $this->form('/tmp/elsewhere'));
        $this->assertStatus(403);
        $this->assertNull(Setting::get(BackupForm::SETTING_PATH));
    }
}
