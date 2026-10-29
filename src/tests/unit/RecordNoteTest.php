<?php

namespace tests\unit;

use app\components\Actor;
use app\models\Attachment;
use app\models\AuditLog;
use app\models\DomainRecord;
use app\models\Lookup;
use app\models\RecordNote;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;
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

    private function attach(DomainRecord $owner, string $name): Attachment
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gwnu');
        file_put_contents($tmp, "Evidence\n");
        $this->temp[] = $tmp;
        $file = Attachment::attach($owner, new UploadedFile(['name' => $name, 'tempName' => $tmp, 'type' => 'text/plain', 'size' => 9, 'error' => UPLOAD_ERR_OK]));
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

        $device = new Device(['hostname' => 'lt-notes-001', 'device_type' => 'laptop', 'status' => 'active', 'criticality' => 'medium', 'data_classification' => 'internal']);
        $this->assertTrue($device->save(), json_encode($device->getErrors()));
        $this->expectException(InvalidConfigException::class);
        RecordNote::add($device, 'Devices take no notes');
    }

    /** One saved record of each type that takes notes, keyed by entity type */
    private function owners(): array
    {
        $risk = new Risk(['title' => 'Phishing', 'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY), 'owner_id' => $this->userId(),
            'status' => Risk::STATUS_ASSESSED, 'date_identified' => '2026-10-01', 'inherent_likelihood' => 2, 'inherent_impact' => 2]);
        $decision = new Decision(['title' => 'Adopt MFA', 'decision_date' => '2026-10-21', 'decision_text' => 'Everyone.',
            'decision_makers' => 'CISO', 'status' => Decision::STATUS_APPROVED, 'category_id' => $this->lookupId(Lookup::TYPE_DECISION_CATEGORY)]);
        $account = new CloudAccount(['name' => 'Production (AWS)', 'provider' => 'aws', 'account_identifier' => '111122223333', 'environment' => 'production',
            'status' => CloudAccount::STATUS_ACTIVE, 'criticality' => 'high', 'data_classification' => 'confidential']);
        foreach ([$risk, $decision, $account] as $record) {
            $this->assertTrue($record->save(), json_encode($record->getErrors()));
        }
        $asset = new CloudAsset(['account_id' => $account->id, 'name' => 'customer-exports', 'resource_type' => 'bucket',
            'status' => CloudAsset::STATUS_ACTIVE, 'criticality' => 'high', 'data_classification' => 'confidential']);
        $this->assertTrue($asset->save(), json_encode($asset->getErrors()));
        $supplier = new Supplier(['name' => 'Fabrikam Managed IT', 'status' => Supplier::STATUS_ACTIVE, 'criticality' => 'high',
            'data_classification' => 'confidential', 'access_level' => Supplier::ACCESS_PRIVILEGED]);
        $this->assertTrue($supplier->save(), json_encode($supplier->getErrors()));
        return ['risk' => $risk, 'control' => $this->control(), 'decision' => $decision, 'cloud_account' => $account, 'cloud_asset' => $asset, 'supplier' => $supplier];
    }

    public function testRisksControlsDecisionsCloudRecordsAndSuppliersTakeNotes(): void
    {
        $this->assertSame(['risk', 'control', 'decision', 'cloud_account', 'cloud_asset', 'supplier'], RecordNote::ENTITY_TYPES);
        $this->assertFalse(RecordNote::takesNotes('incident'), 'incidents keep their timeline');
        foreach ($this->owners() as $type => $owner) {
            /** @var DomainRecord $owner */
            $this->assertTrue(RecordNote::takesNotes($type), $type);
            $note = RecordNote::add($owner, "Note on $type", $this->attach($owner, "$type.txt"));
            $this->assertInstanceOf(RecordNote::class, $note, $type);
            $this->assertSame($owner->id, RecordNote::findOwner($type, $owner->id)->id);
            $this->assertSame(["Note on $type"], array_column(RecordNote::listFor($owner), 'body'));
            $this->assertSame(['note' => [null, "Note on $type · file: $type.txt"]],
                AuditLog::findOne(['entity_type' => $type, 'entity_id' => $owner->id, 'action' => 'note'])->getChangesArray());
        }
    }

    public function testNotesGoWithAHardDeletedRecordOfAnyType(): void
    {
        $owners = $this->owners();
        foreach (['risk', 'decision', 'cloud_asset'] as $type) {
            $note = RecordNote::add($owners[$type], 'Goes with the record');
            $this->assertSame(1, $owners[$type]->delete(), $type);
            $this->assertNull(RecordNote::findOne($note->id), $type);
        }
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
