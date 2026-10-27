<?php

namespace tests\unit;

use app\components\Branding;
use app\models\AuditLog;
use app\models\Setting;
use app\modules\admin\models\BrandingForm;
use tests\DbTestCase;
use Yii;
use yii\db\Connection;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;

class BrandingTest extends DbTestCase
{
    private string $storage;
    private string $originalPath;
    private string $originalName;
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::deleteAll();
        Setting::reset();
        $this->originalPath = Yii::$app->params['branding.path'];
        $this->originalName = Yii::$app->name;
        $this->storage = sys_get_temp_dir() . '/gw-branding-' . bin2hex(random_bytes(4));
        Yii::$app->params['branding.path'] = $this->storage;
    }

    protected function tearDown(): void
    {
        Yii::$app->params['branding.path'] = $this->originalPath;
        Yii::$app->name = $this->originalName;
        FileHelper::removeDirectory($this->storage);
        array_map('unlink', array_filter($this->temp, 'is_file'));
        parent::tearDown();
    }

    private function image(string $type = 'png', int $width = 64, int $height = 64, string $append = ''): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 20, 140, 180));
        ob_start();
        $type === 'png' ? imagepng($img) : imagejpeg($img);
        imagedestroy($img);
        $tmp = tempnam(sys_get_temp_dir(), 'gwlogo');
        file_put_contents($tmp, ob_get_clean() . $append);
        $this->temp[] = $tmp;
        return $tmp;
    }

    private function upload(string $path, string $name = 'logo.png', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile(['name' => $name, 'tempName' => $path, 'type' => 'application/octet-stream', 'size' => (int) @filesize($path), 'error' => $error]);
    }

    public function testDefaultsWhenNothingIsSet(): void
    {
        $this->assertSame(Branding::productName(), Branding::appName());
        $this->assertSame('', Branding::organisation());
        $this->assertSame(Branding::DEFAULT_TAGLINE, Branding::tagline());
        $this->assertSame(Branding::DEFAULT_LOGIN_NOTICE, Branding::loginNotice());
        $this->assertSame(Branding::DEFAULT_CLASSIFICATION, Branding::classification());
        $this->assertNull(Branding::logoName());
        $this->assertNull(Branding::logoUrl());
        $this->assertNull(Branding::logoPath());
        $this->assertNull(Branding::logoMimeType());
        $this->assertNull(Branding::logoDataUri());
    }

    public function testStoredValuesWinAndBlankHides(): void
    {
        Setting::put(['app_name' => '  Acme Security ', 'organisation' => 'Example Ltd', 'tagline' => '', 'login_notice' => null, 'pdf_classification' => 'OFFICIAL']);
        $this->assertSame('Acme Security', Branding::appName());
        $this->assertSame('Example Ltd', Branding::organisation());
        $this->assertSame('', Branding::tagline(), 'saved blank hides the tagline');
        $this->assertSame('', Branding::loginNotice(), 'saved null hides the notice');
        $this->assertSame('OFFICIAL', Branding::classification());

        $row = Setting::findOne('organisation');
        $this->assertNotNull($row->updated_at);

        // A blank display name falls back to the product name
        Setting::put(['app_name' => ' ']);
        $this->assertSame(Branding::productName(), Branding::appName());
        $this->assertSame('fallback', Setting::get('never-saved', 'fallback'));
    }

    public function testApplyPutsTheDisplayNameIntoTheApplication(): void
    {
        Setting::put(['app_name' => 'Acme Security']);
        $this->assertSame('Acme Security', Branding::appName());
        Yii::$app->db->createCommand()->update('setting', ['value' => 'Changed elsewhere'], ['name' => 'app_name'])->execute();
        $this->assertSame('Acme Security', Branding::appName(), 'values are read once per request');
        (new Branding())->bootstrap(Yii::$app);
        $this->assertSame('Changed elsewhere', Yii::$app->name);
    }

    public function testMissingTableReadsAsDefaults(): void
    {
        $db = Yii::$app->db;
        Yii::$app->set('db', new Connection(['dsn' => 'sqlite::memory:']));
        try {
            Setting::reset();
            $this->assertSame([], Setting::values());
            $this->assertSame(Branding::DEFAULT_CLASSIFICATION, Branding::classification());
        } finally {
            Yii::$app->set('db', $db);
            Setting::reset();
        }
    }

    public function testCheckLogo(): void
    {
        $this->assertNull(Branding::checkLogo($this->upload($this->image())));
        $this->assertNull(Branding::checkLogo($this->upload($this->image('jpg'), 'logo.jpg')));

        $text = tempnam(sys_get_temp_dir(), 'gwlogo');
        file_put_contents($text, "<svg onload=alert(1)></svg>\n");
        $this->temp[] = $text;
        $this->assertSame('The logo must be a PNG or JPEG image.', Branding::checkLogo($this->upload($text, 'logo.png')));

        $this->assertStringContainsString('between 16 and 1024 pixels', Branding::checkLogo($this->upload($this->image('png', 8, 64))));
        $this->assertStringContainsString('(this one is 1100 × 20)', Branding::checkLogo($this->upload($this->image('png', 1100, 20))));

        $big = $this->upload($this->image());
        $big->size = (Branding::LOGO_MAX_KB + 1) * 1024;
        $this->assertSame('The logo must be 512 KB or smaller.', Branding::checkLogo($big));
        $this->assertSame('The logo must be 512 KB or smaller.', Branding::checkLogo($this->upload($this->image(), 'logo.png', UPLOAD_ERR_INI_SIZE)));
        $this->assertSame('The logo did not upload completely. Try again.', Branding::checkLogo($this->upload($this->image(), 'logo.png', UPLOAD_ERR_PARTIAL)));
    }

    public function testStoreLogoReencodesAndServesIt(): void
    {
        $name = Branding::storeLogo($this->image('png', 64, 64, '<?php system($_GET["c"]); ?>'));
        $this->assertMatchesRegularExpression('/^logo-[0-9a-f]{12}\.png$/', $name);
        $stored = file_get_contents($this->storage . '/' . $name);
        $this->assertStringNotContainsString('<?php', $stored, 'bytes after the image are dropped by re-encoding');
        $this->assertSame([64, 64], array_slice(getimagesizefromstring($stored), 0, 2));

        Setting::put(['logo' => $name]);
        $this->assertSame($name, Branding::logoName());
        $this->assertSame('image/png', Branding::logoMimeType());
        $this->assertSame('/branding/logo?v=' . substr($name, 5, 12), Branding::logoUrl());
        $this->assertStringStartsWith('data:image/png;base64,', Branding::logoDataUri());

        // The logo in use is never deleted; a replaced one is
        Branding::deleteLogoFile($name);
        $this->assertFileExists($this->storage . '/' . $name);
        $jpeg = Branding::storeLogo($this->image('jpg'));
        $this->assertStringEndsWith('.jpg', $jpeg);
        Setting::put(['logo' => $jpeg]);
        $this->assertSame('image/jpeg', Branding::logoMimeType());
        Branding::deleteLogoFile($name);
        $this->assertFileDoesNotExist($this->storage . '/' . $name);

        // A missing file or a stored name that is not ours reads as "no logo"
        Setting::put(['logo' => '../../etc/passwd']);
        $this->assertNull(Branding::logoName());
        Setting::put(['logo' => 'logo-000000000000.png']);
        $this->assertNull(Branding::logoName());
    }

    public function testStoreLogoRefusesNonImages(): void
    {
        $text = tempnam(sys_get_temp_dir(), 'gwlogo');
        file_put_contents($text, 'not an image');
        $this->temp[] = $text;
        $this->expectException(\RuntimeException::class);
        Branding::storeLogo($text);
    }

    public function testStoreLogoReportsAnUnwritableFolder(): void
    {
        FileHelper::createDirectory($this->storage);
        chmod($this->storage, 0500);
        try {
            Branding::storeLogo($this->image());
            $this->fail('stored into a read-only folder');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be stored', $e->getMessage());
        } finally {
            chmod($this->storage, 0750);
        }
        $this->assertSame([], FileHelper::findFiles($this->storage));
    }

    public function testFormSavesOnlyChangesAndAuditsThem(): void
    {
        $form = BrandingForm::current();
        $this->assertSame('', $form->app_name);
        $this->assertSame(Branding::DEFAULT_TAGLINE, $form->tagline);

        // Nothing changed: nothing stored, nothing audited
        $this->assertTrue($form->save());
        $this->assertSame(0, (int) Setting::find()->count());
        $this->assertFalse(AuditLog::find()->where(['entity_type' => 'setting'])->exists());

        $form = BrandingForm::current();
        $form->organisation = ' Example Ltd ';
        $form->pdf_classification = '';
        $form->logoFile = $this->upload($this->image());
        $this->assertTrue($form->save(), json_encode($form->getErrors()));
        $this->assertSame('Example Ltd', Branding::organisation());
        $this->assertSame('', Branding::classification());
        $this->assertNotNull($logo = Branding::logoName());
        $this->assertSame(['logo', 'organisation', 'pdf_classification'], Setting::find()->select('name')->orderBy('name')->column());

        $entry = AuditLog::find()->where(['entity_type' => 'setting', 'action' => 'update'])->one();
        $this->assertSame('Branding', $entry->entity_label);
        $this->assertNull($entry->getEntityUrl());
        $this->assertSame(['organisation' => ['', 'Example Ltd'], 'pdf_classification' => ['Confidential', ''], 'logo' => [null, $logo]], $entry->getChangesArray());
        $this->assertSame('Organisation', $entry->getFormattedChanges()[0]['field']);
        $this->assertSame('Settings', $entry->getEntityLabelText());

        // Removing the logo deletes its file
        $form = BrandingForm::current();
        $form->remove_logo = true;
        $this->assertTrue($form->save());
        $this->assertNull(Branding::logoName());
        $this->assertFileDoesNotExist($this->storage . '/' . $logo);
    }

    public function testFormValidation(): void
    {
        $form = BrandingForm::current();
        $form->app_name = str_repeat('x', 41);
        $form->logoFile = $this->upload($this->image('png', 8, 8));
        $this->assertFalse($form->save());
        $this->assertArrayHasKey('app_name', $form->getErrors());
        $this->assertStringContainsString('between 16 and 1024 pixels', $form->getFirstError('logoFile'));
        $this->assertSame(0, (int) Setting::find()->count());

        // No file chosen is not an error
        $form = BrandingForm::current();
        $form->logoFile = new UploadedFile(['error' => UPLOAD_ERR_NO_FILE]);
        $this->assertTrue($form->validate());
        $this->assertNotEmpty($form->attributeHints()['logoFile']);
    }

    public function testFormReportsALogoThatCannotBeStored(): void
    {
        // Passes the checks (valid PNG header) but GD cannot decode it
        $png = file_get_contents($this->image());
        $broken = tempnam(sys_get_temp_dir(), 'gwlogo');
        file_put_contents($broken, substr($png, 0, 40));
        $this->temp[] = $broken;
        $form = BrandingForm::current();
        $form->logoFile = $this->upload($broken);
        if (!$form->validate()) {
            $this->markTestSkipped('getimagesize() rejects the truncated PNG on this build');
        }
        $this->assertFalse($form->save());
        $this->assertSame('The logo could not be read as an image.', $form->getFirstError('logoFile'));
    }
}
