<?php

namespace tests\functional;

use app\models\Attachment;
use app\models\Lookup;
use app\modules\incident\models\Incident;
use app\modules\incident\models\IncidentTimeline;
use tests\FunctionalTestCase;
use Yii;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;

/**
 * Incident timeline entries that link a file already on the incident or attach a
 * new one, which then also appears on the incident's Files tab and in the PDF.
 */
class TimelineFileFlowsTest extends FunctionalTestCase
{
    private array $temp = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->temp, 'is_file'));
        FileHelper::removeDirectory(Yii::$app->params['attachments.path']);
        parent::tearDown();
    }

    private function incident(): Incident
    {
        $incident = new Incident(['title' => 'Mailbox compromise', 'category_id' => $this->lookupId(Lookup::TYPE_INCIDENT_CATEGORY),
            'severity' => Incident::SEVERITY_HIGH, 'status' => Incident::STATUS_INVESTIGATING, 'detected_at' => '2026-10-29 09:00:00']);
        $this->assertTrue($incident->save(), json_encode($incident->getErrors()));
        return $incident;
    }

    /** $_FILES for a single "file" upload */
    private function file(string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gwtl');
        file_put_contents($tmp, $content);
        $this->temp[] = $tmp;
        return ['file' => ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)]];
    }

    private function entry(string $body): array
    {
        return ['IncidentTimeline' => ['entry_type' => IncidentTimeline::TYPE_EVIDENCE, 'entry_at' => '2026-10-29T10:00', 'body' => $body]];
    }

    private function files(Incident $incident): array
    {
        return Attachment::find()->where(['entity_type' => 'incident', 'entity_id' => $incident->id])->orderBy('id')->all();
    }

    public function testEntriesAttachOrLinkFiles(): void
    {
        $incident = $this->incident();
        $url = "/incidents/{$incident->id}/add-entry";
        $this->loginAs('analyst');

        $this->get("/incidents/{$incident->id}");
        $this->assertSee('enctype="multipart/form-data"');
        $this->assertSee('id="timeline-file"');
        $this->assertNotSee('id="timeline-attachment"', 'no files to link yet');

        // Browsers send an empty file input as UPLOAD_ERR_NO_FILE
        $this->post($url, $this->entry('Mailbox rules reviewed'), ['file' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]]);
        $this->assertRedirect("/incidents/{$incident->id}");
        $this->assertSame('Timeline entry added.', $this->flash('success'));
        $this->assertNull(IncidentTimeline::findOne(['incident_id' => $incident->id, 'body' => 'Mailbox rules reviewed'])->attachment_id);

        // A new file is attached to the incident and linked from the entry
        $this->post($url, $this->entry('Sign-in logs exported'), $this->file('signins.csv', "user,ip\nalice,192.0.2.1\n"));
        $this->assertSame('Timeline entry added, with signins.csv attached.', $this->flash('success'));
        [$file] = $this->files($incident);
        $entry = IncidentTimeline::findOne(['incident_id' => $incident->id, 'body' => 'Sign-in logs exported']);
        $this->assertSame($file->id, (int) $entry->attachment_id);
        $this->assertSame('signins.csv', $entry->getFileName());

        // Or an existing file on the incident
        $this->post($url, $this->entry('Logs sent to the insurer') + ['attachment_id' => $file->id]);
        $this->assertSame($file->id, (int) IncidentTimeline::findOne(['incident_id' => $incident->id, 'body' => 'Logs sent to the insurer'])->attachment_id);
        $this->assertCount(1, $this->files($incident), 'linking does not copy the file');

        $this->loginAs('viewer');
        $this->get("/incidents/{$incident->id}");
        $this->assertSee('Files (1)');
        $this->assertSee('href="/attachments/' . $file->id . '"');
        $this->assertNotSee('id="timeline-form"');
        $this->get("/incidents/{$incident->id}/pdf");
        $this->assertOk();
        $this->assertStringStartsWith('%PDF-', $this->body);

        // Removing the file keeps the entry and the file's name
        $this->assertTrue($file->remove());
        $this->loginAs('analyst');
        $this->get("/incidents/{$incident->id}");
        $this->assertSee('signins.csv (file since removed)');
        $this->assertNotSee('id="timeline-attachment"');
    }

    public function testRefusedEntriesKeepTheDraftAndStoreNothing(): void
    {
        $incident = $this->incident();
        $url = "/incidents/{$incident->id}/add-entry";
        $this->loginAs('analyst');

        // A bad upload refuses the entry, stores nothing and offers the text again once
        $this->post($url, $this->entry('With a fake PDF'), $this->file('fake.pdf', 'not a pdf'));
        $this->assertStringContainsString('does not contain what its .pdf name says', $this->flash('danger'));
        $this->assertSame([], $this->files($incident));
        $this->assertNull(IncidentTimeline::findOne(['incident_id' => $incident->id, 'body' => 'With a fake PDF']));
        $this->get("/incidents/{$incident->id}");
        $this->assertSee('With a fake PDF</textarea>');
        $this->assertSee('value="evidence" selected');
        $this->get("/incidents/{$incident->id}");
        $this->assertNotSee('With a fake PDF</textarea>', 'the draft is offered once');

        // An invalid entry does not store its upload
        $this->post($url, $this->entry(''), $this->file('ok.txt', 'fine'));
        $this->assertStringContainsString('Timeline entry not added', $this->flash('danger'));
        $this->assertSame([], $this->files($incident));

        // Files from another incident, or both a link and an upload, are refused
        $other = $this->incident();
        $foreign = Attachment::attach($other, new UploadedFile(['name' => 'other.txt', 'tempName' => $this->file('other.txt', 'other')['file']['tmp_name'],
            'type' => 'text/plain', 'size' => 5, 'error' => UPLOAD_ERR_OK]));
        $this->assertInstanceOf(Attachment::class, $foreign);
        $this->post($url, $this->entry('Wrong file') + ['attachment_id' => $foreign->id]);
        $this->assertSame("Timeline entry not added: That file is not attached to {$incident->reference}.", $this->flash('danger'));

        $this->post($url, $this->entry('Mine'), $this->file('mine.txt', 'mine'));
        [$mine] = $this->files($incident);
        $this->post($url, $this->entry('Both') + ['attachment_id' => $mine->id], $this->file('two.txt', 'two'));
        $this->assertSame('Timeline entry not added: Link an existing file or attach a new one, not both.', $this->flash('danger'));
        $this->assertCount(1, $this->files($incident));
        $this->assertNull(IncidentTimeline::findOne(['incident_id' => $incident->id, 'body' => 'Both']));
    }
}
