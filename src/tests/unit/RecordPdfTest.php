<?php

namespace tests\unit;

use app\components\Branding;
use app\components\RecordPdf;
use app\models\Attachment;
use app\models\Lookup;
use app\models\RecordNote;
use app\models\Setting;
use app\modules\control\models\Control;
use app\modules\control\models\ControlTest;
use app\modules\decision\models\Decision;
use app\modules\risk\models\Risk;
use tests\DbTestCase;
use Yii;
use yii\helpers\FileHelper;
use yii\web\View;

class RecordPdfTest extends DbTestCase
{
    private function control(): Control
    {
        $control = new Control(['title' => 'Server patching', 'status' => Control::STATUS_IMPLEMENTED, 'control_type' => 'preventive', 'nature' => 'technical',
            'description' => 'Critical updates within 14 days.']);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        return $control;
    }

    private function risk(array $controlIds = []): Risk
    {
        $risk = new Risk([
            'title' => 'Runtime exploited <script>alert(1)</script>',
            'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY),
            'owner_id' => $this->userId(),
            'inherent_likelihood' => 4, 'inherent_impact' => 5,
            'residual_likelihood' => 3, 'residual_impact' => 5,
            'status' => Risk::STATUS_ASSESSED,
            'date_identified' => '2026-10-20',
            'treatment_plan' => "1. Patch\n2. WAF",
        ]);
        $risk->controlIds = $controlIds;
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        return $risk;
    }

    private function html(object $model, string $module): string
    {
        $id = $model::entityType();
        return RecordPdf::html(new View(), Yii::getAlias("@app/modules/$module/views/$id/pdf.php"), $model, '21 Oct 2026 12:00', 'Test User');
    }

    public function testRiskDocumentShowsScoresLinksAndEncodesText(): void
    {
        $control = $this->control();
        $risk = $this->risk([$control->id]);
        $html = $this->html($risk, 'risk');

        $this->assertStringContainsString('Risk Register', $html);
        $this->assertStringContainsString($risk->reference, $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Likelihood 4', $html);
        $this->assertStringContainsString($control->reference, $html, 'linked control listed');
        $this->assertStringContainsString("1. Patch<br />\n2. WAF", $html);
        $this->assertStringContainsString(Branding::classification(), $html);
    }

    public function testControlDocumentListsTests(): void
    {
        $control = $this->control();
        $test = new ControlTest(['control_id' => $control->id, 'tested_on' => '2026-10-08', 'result' => Control::EFFECTIVENESS_PARTIAL,
            'tested_by_id' => $this->userId(), 'method' => 'Compared object counts']);
        $this->assertTrue($test->save(), json_encode($test->getErrors()));
        $control->refresh();

        $html = $this->html($control, 'control');
        $this->assertStringContainsString('Control Register', $html);
        $this->assertStringContainsString('Compared object counts', $html);
        $this->assertStringContainsString('Partially effective', $html);
        $this->assertStringNotContainsString('<h2>Notes</h2>', $html, 'no notes, no section');
    }

    public function testControlDocumentListsNotesOldestFirst(): void
    {
        $control = $this->control();
        $attachments = Yii::$app->params['attachments.path'];
        Yii::$app->params['attachments.path'] = $dir = sys_get_temp_dir() . '/gw-pdf-notes-' . bin2hex(random_bytes(4));
        try {
            $tmp = tempnam(sys_get_temp_dir(), 'gwpn');
            file_put_contents($tmp, "Email copy\n");
            $file = Attachment::attach($control, new \yii\web\UploadedFile(['name' => 'email.txt', 'tempName' => $tmp, 'type' => 'text/plain', 'size' => 9, 'error' => UPLOAD_ERR_OK]));
            unlink($tmp);
            $first = RecordNote::add($control, 'Emailed DevOps <requesting> xyz', $file);
            $second = RecordNote::add($control, 'Change scheduled');
            RecordNote::updateAll(['created_at' => '2026-10-29 09:00:00'], ['id' => $first->id]);
            RecordNote::updateAll(['created_at' => '2026-10-29 11:00:00'], ['id' => $second->id]);
            $file->remove();

            $html = $this->html($control, 'control');
            $this->assertStringContainsString('<h2>Notes</h2>', $html);
            $this->assertStringContainsString('Emailed DevOps &lt;requesting&gt; xyz', $html);
            $this->assertStringContainsString('File: email.txt (since removed)', $html);
            $this->assertLessThan(strpos($html, 'Change scheduled'), strpos($html, 'Emailed DevOps'), 'oldest first');
        } finally {
            Yii::$app->params['attachments.path'] = $attachments;
            FileHelper::removeDirectory($dir);
        }
    }

    public function testDecisionRendersToPdf(): void
    {
        $decision = new Decision([
            'title' => 'Emergency PHP upgrade', 'decision_date' => '2026-10-21', 'decision_text' => 'Patch on release.',
            'decision_makers' => 'CISO', 'status' => Decision::STATUS_APPROVED, 'category_id' => $this->lookupId(Lookup::TYPE_DECISION_CATEGORY),
        ]);
        $this->assertTrue($decision->save(), json_encode($decision->getErrors()));

        $this->assertStringContainsString('Patch on release.', $this->html($decision, 'decision'));
        $pdf = RecordPdf::render(new View(), Yii::getAlias('@app/modules/decision/views/decision/pdf.php'), $decision);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame($decision->reference . '-emergency-php-upgrade.pdf', RecordPdf::filename($decision));
    }

    public function testLetterheadUsesTheBrandingSettings(): void
    {
        $decision = new Decision([
            'title' => 'Adopt a password manager', 'decision_date' => '2026-10-27', 'decision_text' => 'Roll out to all staff.',
            'decision_makers' => 'CISO', 'status' => Decision::STATUS_APPROVED, 'category_id' => $this->lookupId(Lookup::TYPE_DECISION_CATEGORY),
        ]);
        $this->assertTrue($decision->save(), json_encode($decision->getErrors()));
        $view = Yii::getAlias('@app/modules/decision/views/decision/pdf.php');

        Setting::deleteAll();
        Setting::reset();
        $html = $this->html($decision, 'decision');
        $this->assertStringNotContainsString('<div class="letterhead__org">', $html, 'no organisation set');
        $this->assertStringNotContainsString('letterhead__logo"><img', $html);
        $this->assertStringContainsString('<span class="marking">Confidential</span>', $html, 'default marking');

        $path = Yii::$app->params['branding.path'];
        Yii::$app->params['branding.path'] = $dir = sys_get_temp_dir() . '/gw-pdf-branding-' . bin2hex(random_bytes(4));
        try {
            $img = imagecreatetruecolor(32, 32);
            $tmp = tempnam(sys_get_temp_dir(), 'gwlogo');
            imagepng($img, $tmp);
            Setting::put(['organisation' => 'Example <Ltd>', 'pdf_classification' => '', 'logo' => Branding::storeLogo($tmp)]);
            unlink($tmp);

            $html = $this->html($decision, 'decision');
            $this->assertStringContainsString('<div class="letterhead__org">Example &lt;Ltd&gt;</div>', $html);
            $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
            $this->assertStringNotContainsString('class="marking"', $html, 'blank marking prints none');
            $this->assertStringStartsWith('%PDF-', RecordPdf::render(new View(), $view, $decision));
        } finally {
            Yii::$app->params['branding.path'] = $path;
            FileHelper::removeDirectory($dir);
        }
    }
}
