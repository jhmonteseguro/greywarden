<?php

namespace tests\functional;

use app\components\Branding;
use app\models\AuditLog;
use app\models\Setting;
use tests\FunctionalTestCase;
use Yii;
use yii\helpers\FileHelper;

/**
 * Admin › Settings (branding) over HTTP: saving, logo upload/serving/removal,
 * and where the branding shows up (sidebar, title, sign-in page).
 */
class SettingsTest extends FunctionalTestCase
{
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::deleteAll();
        Setting::reset();
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->temp, 'is_file'));
        FileHelper::removeDirectory(Yii::$app->params['branding.path']);
        parent::tearDown();
    }

    private function form(array $attrs = []): array
    {
        return ['BrandingForm' => $attrs + [
            'app_name' => '', 'organisation' => '', 'tagline' => Branding::DEFAULT_TAGLINE,
            'login_notice' => Branding::DEFAULT_LOGIN_NOTICE, 'pdf_classification' => Branding::DEFAULT_CLASSIFICATION,
        ]];
    }

    /** $_FILES for BrandingForm[logoFile] */
    private function logo(string $content, string $name = 'logo.png'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gwfn');
        file_put_contents($tmp, $content);
        $this->temp[] = $tmp;
        return ['BrandingForm' => ['name' => ['logoFile' => $name], 'type' => ['logoFile' => 'image/png'], 'tmp_name' => ['logoFile' => $tmp],
            'error' => ['logoFile' => UPLOAD_ERR_OK], 'size' => ['logoFile' => strlen($content)]]];
    }

    private function png(int $size = 48): string
    {
        $img = imagecreatetruecolor($size, $size);
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    public function testAdminChangesBranding(): void
    {
        $this->loginAs('admin');
        $this->get('/admin/settings');
        $this->assertOk();
        $this->assertSee('Display name');
        $this->assertSee('placeholder="' . Branding::productName() . '"');
        $this->assertSee('href="/admin/settings"');   // sidebar entry

        $this->post('/admin/settings', $this->form(['app_name' => str_repeat('x', 41)]));
        $this->assertOk();
        $this->assertSee('Display name should contain at most 40 characters.');
        $this->assertSame(0, (int) Setting::find()->count());

        $this->post('/admin/settings', $this->form(['app_name' => 'Acme Security', 'organisation' => 'Example Ltd', 'tagline' => '',
            'login_notice' => 'Authorised use only', 'pdf_classification' => 'OFFICIAL']));
        $this->assertRedirect('/admin/settings');
        $this->assertSame('Branding saved.', $this->flash('success'));
        $this->assertSame('Example Ltd', Setting::get('organisation'));

        $entry = AuditLog::find()->where(['entity_type' => 'setting', 'action' => 'update'])->one();
        $this->assertSame(['', 'Acme Security'], $entry->getChangesArray()['app_name']);
        $this->get("/admin/audit/{$entry->id}");
        $this->assertOk();
        $this->assertSee('Display name');
        $this->assertSee('Acme Security');
        $this->get('/admin/audit?entity_type=setting');
        $this->assertSee('Branding');

        $this->get('/');
        $this->assertSee('<span class="gw-brand__name">Acme Security</span>');
        $this->assertSee('· Acme Security</title>');

        $this->logout();
        $this->get('/login');
        $this->assertSee('<span class="gw-auth__name">Acme Security</span>');
        $this->assertSee('Authorised use only');
        $this->assertNotSee('gw-auth__tagline');
        $this->assertNotSee(Branding::DEFAULT_LOGIN_NOTICE);
    }

    public function testLogoUploadServeAndRemove(): void
    {
        $this->loginAs('admin');
        $this->get('/branding/logo');
        $this->assertStatus(404);

        $this->post('/admin/settings', $this->form(), $this->logo("<svg onload=alert(1)/>", 'logo.png'));
        $this->assertOk();
        $this->assertSee('The logo must be a PNG or JPEG image.');
        $this->assertNull(Branding::logoName());

        $this->post('/admin/settings', $this->form(), $this->logo($this->png()));
        $this->assertRedirect('/admin/settings');
        $name = Branding::logoName();
        $this->assertNotNull($name);
        $url = Branding::logoUrl();

        $this->get('/admin/settings');
        $this->assertSee('<img class="gw-logo gw-logo--custom gw-brand__logo" src="' . $url . '" alt="">');
        $this->assertSee('<link type="image/png" href="' . $url . '" rel="icon">');
        $this->assertSee('BrandingForm[remove_logo]');

        // Public, so the sign-in page can show it
        $this->logout();
        $this->get($url);
        $this->assertOk();
        $this->assertSame('image/png', $this->response->headers->get('content-type'));
        $this->assertSame('public, max-age=31536000, immutable', $this->response->headers->get('cache-control'));
        $this->assertSame(getimagesizefromstring($this->png())[3], getimagesizefromstring($this->body)[3]);
        $this->get('/login');
        $this->assertSee('gw-auth__logo" src="' . $url . '"');

        $this->loginAs('admin');
        $this->post('/admin/settings', $this->form(['remove_logo' => 1]));
        $this->assertRedirect();
        $this->assertNull(Branding::logoName());
        $this->assertFileDoesNotExist(Branding::storagePath() . '/' . $name);
        $this->get($url);
        $this->assertStatus(404);
        $this->get('/admin/settings');
        $this->assertSee('<svg class="gw-logo gw-brand__logo"');
        $this->assertNotSee('BrandingForm[remove_logo]');
    }

    public function testOnlyAdminsChangeSettings(): void
    {
        foreach (['analyst', 'viewer'] as $role) {
            $this->loginAs($role);
            $this->post('/admin/settings', $this->form(['app_name' => 'Hijacked']));
            $this->assertStatus(403);
            $this->get('/');
            $this->assertNotSee('href="/admin/settings"');
        }
        $this->assertNull(Setting::get('app_name'));
    }

    public function testLogoRouteIsAllowedDuringAForcedPasswordChange(): void
    {
        $user = $this->loginAs('viewer');
        $user->updateAttributes(['must_change_password' => 1]);
        $this->get('/branding/logo');
        $this->assertStatus(404, 'reaches the action instead of redirecting to the password page');
    }
}
