<?php

namespace tests\unit;

use app\models\Attachment;
use app\models\AuditLog;
use app\modules\control\models\Control;
use app\modules\control\models\ControlTest;
use tests\DbTestCase;
use Yii;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;

class AttachmentTest extends DbTestCase
{
    private string $storage;
    private string $originalPath;
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPath = Yii::$app->params['attachments.path'];
        $this->storage = sys_get_temp_dir() . '/gw-attachments-' . bin2hex(random_bytes(4));
        Yii::$app->params['attachments.path'] = $this->storage;
    }

    protected function tearDown(): void
    {
        Yii::$app->params['attachments.path'] = $this->originalPath;
        FileHelper::removeDirectory($this->storage);
        array_map('unlink', array_filter($this->temp, 'is_file'));
        parent::tearDown();
    }

    private function control(): Control
    {
        $control = new Control(['title' => 'MFA on cloud services', 'status' => Control::STATUS_IMPLEMENTED, 'control_type' => 'preventive', 'nature' => 'technical']);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        return $control;
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gwup');
        file_put_contents($tmp, $content);
        $this->temp[] = $tmp;
        return new UploadedFile(['name' => $name, 'tempName' => $tmp, 'type' => 'application/octet-stream', 'size' => strlen($content), 'error' => UPLOAD_ERR_OK]);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    private function auditEntries(Control $control, string $action): array
    {
        return AuditLog::find()->where(['entity_type' => 'control', 'entity_id' => $control->id, 'action' => $action])->all();
    }

    public function testAttachStoresFileOutsideWebRootAndLogsIt(): void
    {
        $control = $this->control();
        $png = $this->png();
        $a = Attachment::attach($control, $this->upload('../../MFA report.png', $png), '  Intune export ');

        $this->assertInstanceOf(Attachment::class, $a, is_string($a) ? $a : '');
        $this->assertSame('MFA report.png', $a->original_name, 'directory parts are dropped from the name');
        $this->assertSame('image/png', $a->mime_type);
        $this->assertSame('Intune export', $a->description);
        $this->assertSame(hash('sha256', $png), $a->sha256);
        $this->assertStringStartsWith($this->storage . '/', $a->getPath());
        $this->assertStringNotContainsString('MFA', $a->getPath(), 'stored under a random name');
        $this->assertSame($png, file_get_contents($a->getPath()));
        $this->assertTrue($a->getIsPreviewable());

        $logs = $this->auditEntries($control, 'attach');
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('MFA report.png', $logs[0]->getFormattedChanges()[0]['new']);
        $this->assertSame('File', $logs[0]->getFormattedChanges()[0]['field']);
    }

    public function testRejectsDisallowedMislabelledAndEmptyFiles(): void
    {
        $control = $this->control();
        $this->assertStringContainsString('not an allowed type', Attachment::attach($control, $this->upload('tool.exe', "MZ\x90\x00binary")));
        $this->assertStringContainsString('not an allowed type', Attachment::attach($control, $this->upload('page.html', '<html><script>alert(1)</script></html>')));
        $this->assertStringContainsString('does not contain what its .png name says', Attachment::attach($control, $this->upload('fake.png', '<html><script>alert(1)</script></html>')));
        $this->assertStringContainsString('does not contain what its .pdf name says', Attachment::attach($control, $this->upload('fake.pdf', $this->png())));
        $this->assertStringContainsString('is empty', Attachment::attach($control, $this->upload('empty.txt', '')));
        $this->assertSame('Choose a file to attach.', Attachment::check(null));

        Yii::$app->params['attachments.maxSizeMb'] = 0;
        try {
            $this->assertStringContainsString('larger than', Attachment::attach($control, $this->upload('notes.txt', 'hello')));
        } finally {
            Yii::$app->params['attachments.maxSizeMb'] = 20;
        }
        $this->assertSame(0, Attachment::countFor($control));
        $this->assertSame([], $this->auditEntries($control, 'attach'));
    }

    public function testAcceptsPdfCsvTextAndOfficeFiles(): void
    {
        $control = $this->control();
        $this->assertInstanceOf(Attachment::class, Attachment::attach($control, $this->upload('policy.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n")));
        $this->assertInstanceOf(Attachment::class, Attachment::attach($control, $this->upload('devices.csv', "name,os\nlaptop-1,macOS\n")));
        $this->assertInstanceOf(Attachment::class, Attachment::attach($control, $this->upload('notes.txt', "Checked on site.\n")));

        $zipPath = tempnam(sys_get_temp_dir(), 'gwdocx');
        $this->temp[] = $zipPath;
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        $zip->close();
        $docx = Attachment::attach($control, $this->upload('report.docx', (string) file_get_contents($zipPath)));
        $this->assertInstanceOf(Attachment::class, $docx, is_string($docx) ? $docx : '');
        $this->assertSame('file-earmark-word', $docx->getIcon());
        $this->assertFalse($docx->getIsPreviewable(), 'only images are shown inline');

        $this->assertSame(4, Attachment::countFor($control));
    }

    public function testRemoveDeletesFileAndLogsIt(): void
    {
        $control = $this->control();
        $a = Attachment::attach($control, $this->upload('notes.txt', 'evidence'));
        $path = $a->getPath();
        $this->assertTrue($a->remove());
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, Attachment::countFor($control));
        $logs = $this->auditEntries($control, 'detach');
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('notes.txt', $logs[0]->getFormattedChanges()[0]['old']);
    }

    public function testHardDeletingTheRecordDeletesItsFiles(): void
    {
        $control = $this->control();
        $path = Attachment::attach($control, $this->upload('notes.txt', 'evidence'))->getPath();
        $control->delete();
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, (int) Attachment::find()->where(['entity_type' => 'control', 'entity_id' => $control->id])->count());
    }

    public function testTestEvidenceFilesAreValidatedAndKeptWhenTheTestIsRemoved(): void
    {
        $control = $this->control();
        $test = new ControlTest(['control_id' => $control->id, 'tested_on' => date('Y-m-d'), 'result' => Control::EFFECTIVENESS_EFFECTIVE]);
        $test->evidenceFiles = [$this->upload('fake.png', 'not an image')];
        $this->assertFalse($test->validate());
        $this->assertTrue($test->hasErrors('evidenceFiles'));

        $test->evidenceFiles = [$this->upload('screenshot.png', $this->png())];
        $this->assertTrue($test->save(), json_encode($test->getErrors()));
        $this->assertSame([], $test->attachEvidenceFiles());

        $a = Attachment::findOne(['entity_type' => 'control', 'entity_id' => $control->id]);
        $this->assertSame($test->id, $a->control_test_id);
        $this->assertStringContainsString('test of', $a->getAuditText());

        $test->delete();
        $a->refresh();
        $this->assertNull($a->control_test_id, 'the file stays on the control');
        $this->assertFileExists($a->getPath());
    }

    public function testOnlyRegisterRecordsTakeAttachments(): void
    {
        $this->assertNull(Attachment::findOwner('user', 1));
        $this->assertNull(Attachment::findOwner('control', PHP_INT_MAX));
        $this->expectException(\yii\base\InvalidConfigException::class);
        Attachment::ownerClass('device');
    }
}
