<?php

namespace tests\functional;

use app\components\Actor;
use app\models\Attachment;
use app\models\AuditLog;
use app\models\RecordNote;
use app\modules\control\models\Control;
use tests\FunctionalTestCase;
use Yii;
use yii\helpers\FileHelper;

/**
 * Adding and removing dated notes on a control over HTTP, with linked and newly
 * attached files, and the note's place on the page and in the PDF.
 */
class NoteFlowsTest extends FunctionalTestCase
{
    private array $temp = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->temp, 'is_file'));
        FileHelper::removeDirectory(Yii::$app->params['attachments.path']);
        parent::tearDown();
    }

    private function control(): Control
    {
        $control = new Control(['title' => 'Firewall change control', 'status' => Control::STATUS_IMPLEMENTING, 'control_type' => 'preventive', 'nature' => 'technical']);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        return $control;
    }

    /** $_FILES for a single "file" upload */
    private function file(string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gwnote');
        file_put_contents($tmp, $content);
        $this->temp[] = $tmp;
        return ['file' => ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)]];
    }

    private function notes(Control $control): array
    {
        return RecordNote::find()->where(['entity_type' => 'control', 'entity_id' => $control->id])->orderBy('id')->all();
    }

    public function testAddNotesWithAndWithoutFiles(): void
    {
        $control = $this->control();
        $this->loginAs('analyst');
        $url = "/notes/add?entity=control&id={$control->id}";

        $this->get("/controls/{$control->id}");
        $this->assertSee('id="tab-notes"');
        $this->assertSee('No notes yet.');
        $this->assertSee('id="note-form"');

        // Browsers send an empty file input as UPLOAD_ERR_NO_FILE
        $this->post($url, ['body' => '  Emailed DevOps requesting the rule change  '],
            ['file' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]]);
        $this->assertRedirect("/controls/{$control->id}");
        $this->assertSame("Note added to {$control->reference}.", $this->flash('success'));
        [$note] = $this->notes($control);
        $this->assertSame('Emailed DevOps requesting the rule change', $note->body);
        $this->assertSame($this->userIdFor('analyst'), (int) $note->created_by);
        $this->assertNull($note->attachment_id);

        // A new file is attached to the control and linked from the note
        $this->post($url, ['body' => 'Copy of the email'], $this->file('email.txt', "Copy of the email to DevOps\n"));
        $this->assertSame("Note added to {$control->reference}, with email.txt attached.", $this->flash('success'));
        $note = $this->notes($control)[1];
        $file = Attachment::findOne(['entity_type' => 'control', 'entity_id' => $control->id]);
        $this->assertSame($file->id, (int) $note->attachment_id);
        $this->assertSame('email.txt', $note->attachment_name);

        // Or an existing file on the record
        $this->post($url, ['body' => 'Reply received, see the same email', 'attachment_id' => $file->id]);
        $this->assertSame($file->id, (int) $this->notes($control)[2]->attachment_id);

        $this->assertSame(['note', 'attach', 'note', 'note'], AuditLog::find()->select('action')
            ->where(['entity_type' => 'control', 'entity_id' => $control->id, 'action' => ['note', 'attach']])->orderBy('id')->column());

        $this->loginAs('viewer');
        $this->get("/controls/{$control->id}");
        $this->assertSee('Notes (3)');
        $this->assertSee('Emailed DevOps requesting the rule change');
        $this->assertSee('href="/attachments/' . $file->id . '"');
        $this->assertNotSee('id="note-form"');
        $this->assertNotSee('/notes/');

        $this->get("/controls/{$control->id}/pdf");
        $this->assertOk();
        $this->assertStringStartsWith('%PDF-', $this->body);
    }

    public function testRefusedNotesKeepTheDraftAndStoreNothing(): void
    {
        $control = $this->control();
        $this->loginAs('analyst');
        $url = "/notes/add?entity=control&id={$control->id}";

        $this->post($url, ['body' => '   ']);
        $this->assertSame('Write the note.', $this->flash('danger'));

        $long = str_repeat('x', RecordNote::MAX_LENGTH + 1);
        $this->post($url, ['body' => $long]);
        $this->assertStringContainsString('Keep notes to', $this->flash('danger'));
        $this->get("/controls/{$control->id}");
        $this->assertSee($long);
        $this->assertNull($_SESSION[RecordNote::draftKey($control)] ?? null, 'the draft is offered once');

        // A bad upload refuses the note too, and the file is not stored
        $this->post($url, ['body' => 'With a fake PDF'], $this->file('fake.pdf', 'not a pdf'));
        $this->assertStringContainsString('does not contain what its .pdf name says', $this->flash('danger'));

        // A blank note does not store its upload
        $this->post($url, ['body' => ''], $this->file('ok.txt', 'fine'));
        $this->assertSame('Write the note.', $this->flash('danger'));

        // Files from another record, or both a link and an upload, are refused
        $other = $this->control();
        $foreign = Attachment::attach($other, new \yii\web\UploadedFile(['name' => 'other.txt', 'tempName' => $this->file('other.txt', 'other')['file']['tmp_name'],
            'type' => 'text/plain', 'size' => 5, 'error' => UPLOAD_ERR_OK]));
        $this->assertInstanceOf(Attachment::class, $foreign);
        $this->post($url, ['body' => 'Wrong file', 'attachment_id' => $foreign->id]);
        $this->assertSame("That file is not attached to {$control->reference}.", $this->flash('danger'));

        $this->post($url, ['body' => 'Mine'], $this->file('mine.txt', 'mine'));
        $mine = Attachment::findOne(['entity_type' => 'control', 'entity_id' => $control->id]);
        $this->post($url, ['body' => 'Both', 'attachment_id' => $mine->id], $this->file('two.txt', 'two'));
        $this->assertSame('Link an existing file or attach a new one, not both.', $this->flash('danger'));

        $this->assertCount(1, $this->notes($control));
        $this->assertSame(1, (int) Attachment::find()->where(['entity_type' => 'control', 'entity_id' => $control->id])->count());
    }

    public function testAccessArchivedRecordsAndRemoval(): void
    {
        $control = $this->control();
        $url = "/notes/add?entity=control&id={$control->id}";

        $this->loginAs('viewer');
        $this->post($url, ['body' => 'Viewer note']);
        $this->assertStatus(403);

        $this->loginAs('analyst');
        $this->post('/notes/add?entity=control&id=999999', ['body' => 'x']);
        $this->assertStatus(404);
        $this->post('/notes/add?entity=device&id=1', ['body' => 'x']);
        $this->assertStatus(404, 'devices take no notes');
        $this->post('/notes/999999/remove');
        $this->assertStatus(404);
        $this->get($url);
        $this->assertStatus(405);

        $this->post($url, ['body' => 'Analyst note']);
        [$own] = $this->notes($control);
        Actor::override($this->userIdFor('admin'), 'Admin');
        $theirs = RecordNote::add($control, 'Admin note');
        Actor::override(null);

        // Analysts remove only their own notes; administrators any
        $this->post("/notes/{$theirs->id}/remove");
        $this->assertStatus(403);
        $this->get("/controls/{$control->id}");
        $this->assertSee('/notes/' . $own->id . '/remove');
        $this->assertNotSee('/notes/' . $theirs->id . '/remove');

        $this->post("/notes/{$own->id}/remove");
        $this->assertRedirect("/controls/{$control->id}");
        $this->assertStringContainsString('Note removed', $this->flash('info'));
        $this->assertNull(RecordNote::findOne($own->id));
        $removed = AuditLog::findOne(['entity_type' => 'control', 'entity_id' => $control->id, 'action' => 'note_remove']);
        $this->assertSame(['note' => ['Analyst note', null]], $removed->getChangesArray());

        // Archived records are read-only
        $control->archive();
        $this->post($url, ['body' => 'Too late']);
        $this->assertStringContainsString('Archived records are read-only', $this->flash('warning'));
        $this->loginAs('admin');
        $this->post("/notes/{$theirs->id}/remove");
        $this->assertStringContainsString('Archived records are read-only', $this->flash('warning'));
        $this->assertNotNull(RecordNote::findOne($theirs->id));
        $this->get("/controls/{$control->id}");
        $this->assertSee('Admin note');
        $this->assertNotSee('id="note-form"');

        $control->restore();
        $this->post("/notes/{$theirs->id}/remove");
        $this->assertNull(RecordNote::findOne($theirs->id));
    }
}
