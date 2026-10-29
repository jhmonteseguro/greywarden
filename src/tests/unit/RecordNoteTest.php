<?php

namespace tests\unit;

use app\components\Actor;
use app\models\Attachment;
use app\models\AuditLog;
use app\models\Lookup;
use app\models\RecordNote;
use app\modules\control\models\Control;
use app\modules\risk\models\Risk;
use tests\DbTestCase;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;

class RecordNoteTest extends DbTestCase
{
    private string $originalPath;
    private string $storage;
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPath = Yii::$app->params['attachments.path'];
        $this->storage = sys_get_temp_dir() . '/gw-notes-' . bin2hex(random_bytes(4));
        Yii::$app->params['attachments.path'] = $this->storage;
        Actor::override($this->userId(), 'Test User');
    }

    protected function tearDown(): void
    {
        Actor::override(null);
        Yii::$app->params['attachments.path'] = $this->originalPath;
        FileHelper::removeDirectory($this->storage);
        array_map('unlink', array_filter($this->temp, 'is_file'));
        parent::tearDown();
    }

    private function control(): Control
    {
        $control = new Control(['title' => 'Change management', 'status' => Control::STATUS_IMPLEMENTING, 'control_type' => 'preventive', 'nature' => 'technical']);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        return $control;
    }

    private function attach(Control $control, string $name): Attachment
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gwnu');
        file_put_contents($tmp, "Evidence\n");
        $this->temp[] = $tmp;
        $file = Attachment::attach($control, new UploadedFile(['name' => $name, 'tempName' => $tmp, 'type' => 'text/plain', 'size' => 9, 'error' => UPLOAD_ERR_OK]));
        $this->assertInstanceOf(Attachment::class, $file);
        return $file;
    }

    public function testAddStampsTheNoteAndLogsIt(): void
    {
        $control = $this->control();
        $note = RecordNote::add($control, "  Emailed DevOps\nrequesting xyz  ");

        $this->assertInstanceOf(RecordNote::class, $note);
        $this->assertSame("Emailed DevOps\nrequesting xyz", $note->body);
        $this->assertSame($this->userId(), (int) $note->created_by);
        $this->assertNotEmpty($note->created_at);
        $this->assertNull($note->getFileName());
        $this->assertFalse($note->getIsFileRemoved());

        $log = AuditLog::findOne(['entity_type' => 'control', 'entity_id' => $control->id, 'action' => 'note']);
        $this->assertSame(['note' => [null, "Emailed DevOps\nrequesting xyz"]], $log->getChangesArray());
        $this->assertSame('Note added', $log->getActionLabel());
        $this->assertSame('Note removed', AuditLog::ACTION_LABELS['note_remove']);
    }

    public function testRefusesEmptyLongAndOtherRecords(): void
    {
        $control = $this->control();
        $this->assertSame('Write the note.', RecordNote::add($control, " \n "));
        $this->assertSame('Write the note.', RecordNote::check(null));
        $this->assertStringContainsString('Keep notes to 1000 characters', RecordNote::add($control, str_repeat('a', 1001)));
        $this->assertNull(RecordNote::check(str_repeat('a', 1000)));

        $other = $this->control();
        $this->assertSame("That file is not attached to {$control->reference}.", RecordNote::add($control, 'Wrong file', $this->attach($other, 'other.txt')));
        $this->assertSame(0, (int) RecordNote::find()->where(['entity_id' => $control->id])->count());

        $this->assertTrue(RecordNote::takesNotes('control'));
        $this->assertFalse(RecordNote::takesNotes('device'));
        $this->assertNull(RecordNote::findOwner('device', 1));
        $this->assertNull(RecordNote::findOwner('control', 999999));
        $this->assertSame($control->id, RecordNote::findOwner('control', $control->id)->id);

        $risk = new Risk(['title' => 'Not yet', 'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY), 'owner_id' => $this->userId(),
            'status' => Risk::STATUS_ASSESSED, 'date_identified' => '2026-10-01', 'inherent_likelihood' => 2, 'inherent_impact' => 2]);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        $this->expectException(InvalidConfigException::class);
        RecordNote::add($risk, 'Risks take no notes yet');
    }

    public function testLinkedFileKeepsItsNameAfterRemoval(): void
    {
        $control = $this->control();
        $file = $this->attach($control, 'email.txt');
        $note = RecordNote::add($control, 'Copy of the email', $file);
        $this->assertSame('email.txt', $note->getFileName());
        $this->assertSame('Copy of the email · file: email.txt', $note->getAuditText());

        $this->assertTrue($file->remove());
        $note = RecordNote::findOne($note->id);
        $this->assertNull($note->attachment_id);
        $this->assertNull($note->attachment);
        $this->assertSame('email.txt', $note->getFileName());
        $this->assertTrue($note->getIsFileRemoved());
    }

    public function testListOrderRemovalAndHardDelete(): void
    {
        $control = $this->control();
        $first = RecordNote::add($control, 'First');
        $second = RecordNote::add($control, 'Second');
        // Same second: the id breaks the tie
        RecordNote::updateAll(['created_at' => '2026-10-29 10:00:00'], ['id' => [$first->id, $second->id]]);

        $this->assertSame(['Second', 'First'], array_column(RecordNote::listFor($control), 'body'));
        $this->assertSame(['First', 'Second'], array_column(RecordNote::listFor($control, true), 'body'));

        $this->assertTrue($first->remove());
        $this->assertSame(['note' => ['First', null]],
            AuditLog::findOne(['entity_type' => 'control', 'entity_id' => $control->id, 'action' => 'note_remove'])->getChangesArray());

        $this->assertSame(1, $control->delete());
        $this->assertNull(RecordNote::findOne($second->id), 'notes go with a hard-deleted record');
    }

    public function testDraftIsReturnedOnce(): void
    {
        $control = $this->control();
        $this->assertSame('note-draft.control.' . $control->id, RecordNote::draftKey($control));
        $this->assertSame('', RecordNote::takeDraft($control), 'no session in the console app');
    }
}
