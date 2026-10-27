<?php

namespace tests\functional;

use app\models\Attachment;
use app\models\AuditLog;
use app\models\Lookup;
use app\modules\control\models\Control;
use app\modules\control\models\ControlTest;
use app\modules\risk\models\Risk;
use tests\FunctionalTestCase;
use Yii;
use yii\helpers\FileHelper;

/**
 * Uploading, downloading and removing attachments over HTTP (files go to a temp
 * storage folder, see tests/bootstrap.php), plus control-test evidence.
 */
class AttachmentFlowsTest extends FunctionalTestCase
{
    private array $temp = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->temp, 'is_file'));
        FileHelper::removeDirectory(Yii::$app->params['attachments.path']);
        parent::tearDown();
    }

    private function risk(): Risk
    {
        $risk = new Risk(['title' => 'Attachment risk', 'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY), 'owner_id' => $this->userIdFor('analyst'),
            'status' => Risk::STATUS_ASSESSED, 'date_identified' => '2026-01-01', 'inherent_likelihood' => 3, 'inherent_impact' => 3]);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        return $risk;
    }

    /** One $_FILES entry for a file with $content */
    private function file(string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gwfn');
        file_put_contents($tmp, $content);
        $this->temp[] = $tmp;
        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function files(array ...$files): array
    {
        $out = [];
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
            $out['files'][$k] = array_column($files, $k);
        }
        return $out;
    }

    public function testUploadDownloadAndRemove(): void
    {
        $risk = $this->risk();
        $this->loginAs('analyst');

        $this->post("/attachments/upload?entity=risk&id={$risk->id}", ['description' => 'Evidence pack'],
            $this->files($this->file('notes.txt', "Plain text evidence\n"), $this->file('fake.pdf', 'not a pdf')));
        $this->assertRedirect("/risks/{$risk->id}");
        $this->assertSame('Attached to ' . $risk->reference . ': notes.txt.', $this->flash('success'));
        $this->assertStringContainsString('does not contain what its .pdf name says', $this->flash('danger'));

        $attachment = Attachment::findOne(['entity_type' => 'risk', 'entity_id' => $risk->id]);
        $this->assertSame('Evidence pack', $attachment->description);
        $this->assertFileExists($attachment->getPath());
        $this->assertStringStartsWith(Yii::$app->params['attachments.path'], $attachment->getPath());

        $this->get("/risks/{$risk->id}");
        $this->assertSee('notes.txt');

        $this->loginAs('viewer');
        $this->get("/attachments/{$attachment->id}");
        $this->assertOk();
        $this->assertSame("Plain text evidence\n", $this->body);
        $this->assertStringContainsString('sandbox', $this->response->headers->get('content-security-policy'));
        $this->assertStringContainsString('attachment;', $this->response->headers->get('content-disposition'));
        $this->get("/attachments/{$attachment->id}?inline=1");
        $this->assertStringContainsString('attachment;', $this->response->headers->get('content-disposition'), 'only images / PDFs are shown inline');

        $img = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $this->loginAs('analyst');
        $this->post("/attachments/upload?entity=risk&id={$risk->id}", [], $this->files($this->file('diagram.png', $png)));
        $image = Attachment::findOne(['entity_type' => 'risk', 'entity_id' => $risk->id, 'original_name' => 'diagram.png']);
        $this->loginAs('viewer');
        $this->get("/attachments/{$image->id}?inline=1");
        $this->assertStringStartsWith('inline;', $this->response->headers->get('content-disposition'));
        $this->assertSame('image/png', $this->response->headers->get('content-type'));

        $this->post("/attachments/{$attachment->id}/remove");
        $this->assertStatus(403);

        $this->loginAs('analyst');
        $this->post("/attachments/{$attachment->id}/remove");
        $this->assertRedirect("/risks/{$risk->id}");
        $this->assertSame('Removed notes.txt.', $this->flash('info'));
        $this->assertNull(Attachment::findOne($attachment->id));
        $this->assertSame(['attach', 'attach', 'detach'], AuditLog::find()->select('action')
            ->where(['entity_type' => 'risk', 'entity_id' => $risk->id, 'action' => ['attach', 'detach']])->orderBy('id')->column());
    }

    public function testUploadEdgeCases(): void
    {
        $risk = $this->risk();
        $this->loginAs('analyst');

        $this->post("/attachments/upload?entity=risk&id={$risk->id}");
        $this->assertRedirect();
        $this->assertStringContainsString('Nothing was received', $this->flash('danger'));

        $this->post('/attachments/upload?entity=risk&id=999999', [], $this->files($this->file('a.txt', 'x')));
        $this->assertStatus(404);
        $this->post('/attachments/upload?entity=device&id=1', [], $this->files($this->file('a.txt', 'x')));
        $this->assertStatus(404, 'devices take no attachments');
        $this->post('/attachments/999999/remove');
        $this->assertStatus(404);

        // Archived records are read-only
        $this->post("/attachments/upload?entity=risk&id={$risk->id}", [], $this->files($this->file('a.txt', 'Some text')));
        $attachment = Attachment::findOne(['entity_id' => $risk->id, 'entity_type' => 'risk']);
        $risk->archive();
        $this->post("/attachments/upload?entity=risk&id={$risk->id}", [], $this->files($this->file('b.txt', 'More text')));
        $this->assertStringContainsString('Archived records are read-only', $this->flash('warning'));
        $this->post("/attachments/{$attachment->id}/remove");
        $this->assertStringContainsString('Archived records are read-only', $this->flash('warning'));
        $this->assertNotNull(Attachment::findOne($attachment->id));

        // A file missing from disk is a 404, and logged
        unlink($attachment->getPath());
        $this->get("/attachments/{$attachment->id}");
        $this->assertStatus(404);
        $this->assertStringContainsString('missing from storage', $this->body);
    }

    public function testControlTestEvidenceFiles(): void
    {
        $control = new Control(['title' => 'Backups restore test', 'status' => Control::STATUS_IMPLEMENTED, 'control_type' => 'corrective', 'nature' => 'technical']);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        $this->loginAs('analyst');

        $evidence = static function (array ...$list): array {
            $files = ['ControlTest' => []];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
                $files['ControlTest'][$k]['evidenceFiles'] = array_column($list, $k);
            }
            return $files;
        };
        $form = ['ControlTest' => ['tested_on' => date('Y-m-d'), 'result' => 'effective', 'method' => 'Restore']];

        // A disallowed file fails the whole test record
        $this->post("/controls/{$control->id}/record-test", $form, $evidence($this->file('restore-log.txt', "restore ok\n"), $this->file('screenshot.exe', 'MZ')));
        $this->assertOk();
        $this->assertSee('not an allowed type');
        $this->assertFalse(ControlTest::find()->where(['control_id' => $control->id])->exists());

        $this->post("/controls/{$control->id}/record-test", $form, $evidence($this->file('restore-log.txt', "restore ok\n")));
        $this->assertRedirect("/controls/{$control->id}");
        $test = ControlTest::findOne(['control_id' => $control->id]);
        $evidence = Attachment::findAll(['control_test_id' => $test->id]);
        $this->assertSame(['restore-log.txt'], array_column($evidence, 'original_name'));
        $this->get("/controls/{$control->id}");
        $this->assertSee('restore-log.txt');
    }
}
