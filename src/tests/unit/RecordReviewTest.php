<?php

namespace tests\unit;

use app\components\Actor;
use app\models\AuditLog;
use app\models\Lookup;
use app\models\RecordReview;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\risk\models\Risk;
use tests\DbTestCase;
use yii\base\InvalidConfigException;

class RecordReviewTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Actor::override($this->userId(), 'Test User');
    }

    protected function tearDown(): void
    {
        Actor::override(null);
        parent::tearDown();
    }

    private function risk(array $attributes = []): Risk
    {
        $risk = new Risk($attributes + ['title' => 'Phishing', 'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY), 'owner_id' => $this->userId(),
            'status' => Risk::STATUS_ASSESSED, 'date_identified' => '2026-01-01', 'inherent_likelihood' => 2, 'inherent_impact' => 2, 'next_review_date' => '2026-04-01']);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        return $risk;
    }

    private function decision(array $attributes = []): Decision
    {
        $decision = new Decision($attributes + ['title' => 'Adopt MFA', 'decision_date' => '2026-01-01', 'decision_text' => 'Everyone.',
            'decision_makers' => 'CISO', 'status' => Decision::STATUS_APPROVED, 'category_id' => $this->lookupId(Lookup::TYPE_DECISION_CATEGORY), 'review_date' => '2026-06-01']);
        $this->assertTrue($decision->save(), json_encode($decision->getErrors()));
        return $decision;
    }

    public function testRecordingAReviewMovesTheReviewDateOnAndLogsIt(): void
    {
        $risk = $this->risk();
        $before = (int) AuditLog::find()->where(['entity_type' => 'risk', 'entity_id' => $risk->id])->count();

        $review = RecordReview::record($risk, ['reviewed_on' => '2026-04-10', 'outcome' => '  Still valid, no change to the scores.  ',
            'next_review_date' => '2026-07-10', 'ignored' => 'x']);
        $this->assertFalse($review->hasErrors(), json_encode($review->getErrors()));
        $this->assertSame('Still valid, no change to the scores.', $review->outcome);
        $this->assertSame($this->userId(), (int) $review->reviewed_by_id, 'defaults to the current user');
        $this->assertSame($this->userId(), (int) $review->created_by);
        $this->assertSame('2026-04-01', $review->due_on);
        $this->assertTrue($review->getWasLate());

        $risk->refresh();
        $this->assertSame('2026-07-10', $risk->next_review_date);
        $this->assertFalse($risk->isReviewOverdue('2026-05-01'));

        // One history entry carrying both the review and the date change
        $entries = AuditLog::find()->where(['entity_type' => 'risk', 'entity_id' => $risk->id])->orderBy('id')->all();
        $this->assertCount($before + 1, $entries);
        $log = end($entries);
        $this->assertSame('review', $log->action);
        $this->assertSame('Review recorded', $log->getActionLabel());
        $changes = $log->getChangesArray();
        $this->assertSame(['2026-04-01', '2026-07-10'], $changes['next_review_date']);
        $this->assertSame('Reviewed ' . \Yii::$app->formatter->asDate('2026-04-10') . ' by ' . $review->reviewedBy->full_name . ': Still valid, no change to the scores.', $changes['review'][1]);

        $this->assertSame($review->id, RecordReview::latestFor($risk)->id);
    }

    public function testDecisionsUseTheirReviewDateAndAnotherReviewerCanBeNamed(): void
    {
        $decision = $this->decision();
        $other = \app\models\User::createUser('reviewer2', 'reviewer2@example.com', 'Second Reviewer', 'Test!Password123', 'analyst', false);

        $review = RecordReview::record($decision, ['reviewed_on' => '2026-05-20', 'reviewed_by_id' => (string) $other->id,
            'outcome' => 'Reaffirmed by the board.', 'next_review_date' => '2027-05-20']);
        $this->assertFalse($review->hasErrors(), json_encode($review->getErrors()));
        $this->assertFalse($review->getWasLate(), 'reviewed before it was due');
        $this->assertSame('2027-05-20', $decision->refresh() ? $decision->review_date : null);
        $this->assertSame('Second Reviewer', $review->reviewedBy->full_name);
        $this->assertSame('review_date', RecordReview::dateAttribute($decision));

        // Same next date as before: the history entry carries only the review
        $again = RecordReview::record($decision, ['reviewed_on' => '2026-05-21', 'outcome' => 'Checked again.', 'next_review_date' => '2027-05-20']);
        $this->assertFalse($again->hasErrors());
        $log = AuditLog::find()->where(['entity_type' => 'decision', 'entity_id' => $decision->id, 'action' => 'review'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertSame(['review'], array_keys($log->getChangesArray()));

        $this->assertSame([$again->id, $review->id], array_map(static fn($r) => $r->id, RecordReview::listFor($decision)));
    }

    public function testRefusedReviewsChangeNothing(): void
    {
        $risk = $this->risk();
        $cases = [
            'no outcome' => [['reviewed_on' => '2026-04-10', 'outcome' => '   ', 'next_review_date' => '2026-07-10'], 'outcome', 'Outcome cannot be blank.'],
            'future' => [['reviewed_on' => date('Y-m-d', strtotime('+2 days')), 'outcome' => 'x', 'next_review_date' => date('Y-m-d', strtotime('+90 days'))],
                'reviewed_on', 'The review date cannot be in the future.'],
            'next before' => [['reviewed_on' => '2026-04-10', 'outcome' => 'x', 'next_review_date' => '2026-04-10'], 'next_review_date', 'The next review date must be after the review date.'],
            'no next' => [['reviewed_on' => '2026-04-10', 'outcome' => 'x', 'next_review_date' => ''], 'next_review_date',
                'Set the next review date, or close the record if it no longer needs reviewing.'],
            'too long' => [['reviewed_on' => '2026-04-10', 'outcome' => str_repeat('x', RecordReview::MAX_LENGTH + 1), 'next_review_date' => '2026-07-10'],
                'outcome', 'Keep the outcome to ' . RecordReview::MAX_LENGTH . ' characters.'],
            'bad date' => [['reviewed_on' => '10/04/2026', 'outcome' => 'x', 'next_review_date' => '2026-07-10'], 'reviewed_on', null],
            'unknown reviewer' => [['reviewed_on' => '2026-04-10', 'reviewed_by_id' => '999999', 'outcome' => 'x', 'next_review_date' => '2026-07-10'], 'reviewed_by_id', null],
        ];
        foreach ($cases as $name => [$data, $attribute, $message]) {
            $review = RecordReview::record($risk, $data);
            $this->assertTrue($review->hasErrors($attribute), $name);
            if ($message !== null) {
                $this->assertSame($message, $review->getFirstError($attribute), $name);
            }
            $this->assertTrue($review->isNewRecord, $name);
        }
        $this->assertSame('2026-04-01', $risk->refresh() ? $risk->next_review_date : null);
        $this->assertSame([], RecordReview::listFor($risk));
        $this->assertNull(RecordReview::latestFor($risk));
        $this->assertSame(0, (int) AuditLog::find()->where(['entity_type' => 'risk', 'entity_id' => $risk->id, 'action' => 'review'])->count());
    }

    public function testRecordsThatNoLongerNeedReviewingMayEndTheSchedule(): void
    {
        $risk = $this->risk();
        $risk->status = Risk::STATUS_CLOSED;
        $risk->save(false);
        $this->assertFalse($risk->isReviewable());

        $review = RecordReview::record($risk, ['reviewed_on' => '2026-04-10', 'outcome' => 'Closed: system decommissioned.', 'next_review_date' => '']);
        $this->assertFalse($review->hasErrors(), json_encode($review->getErrors()));
        $this->assertNull($risk->refresh() ? $risk->next_review_date : 'x');
        $this->assertNull(RecordReview::draft($risk)->next_review_date, 'no next date offered once closed');

        $decision = $this->decision();
        $decision->status = Decision::STATUS_REJECTED;
        $this->assertFalse($decision->isReviewable());
        $this->assertFalse((new Control())->isReviewable(), 'other records are not reviewed');
    }

    public function testDraftsOfferTheUsualInterval(): void
    {
        $today = Actor::today();
        $risk = RecordReview::draft($this->risk());
        $this->assertSame($today, $risk->reviewed_on);
        $this->assertSame($this->userId(), $risk->reviewed_by_id);
        $this->assertSame(date('Y-m-d', strtotime('+90 days', strtotime($today))), $risk->next_review_date);
        $this->assertSame(date('Y-m-d', strtotime('+1 year', strtotime($today))), RecordReview::draft($this->decision())->next_review_date);
        $this->assertSame($risk->reviewed_on, RecordReview::formModel($this->risk())->reviewed_on, 'no session: a fresh draft');

        $this->assertTrue(RecordReview::takesReviews('risk'));
        $this->assertFalse(RecordReview::takesReviews('control'));
        $this->assertNull(RecordReview::findOwner('control', 1));
        $this->assertSame('review-draft.risk.7', RecordReview::draftKey(new Risk(['id' => 7])));
        $this->assertArrayHasKey('outcome', (new RecordReview())->attributeHints());
        $this->assertSame('Was due', (new RecordReview())->getAttributeLabel('due_on'));
    }

    public function testOnlyRisksAndDecisionsAreReviewed(): void
    {
        $this->expectException(InvalidConfigException::class);
        RecordReview::record(new Control(['id' => 1]), ['reviewed_on' => '2026-04-10', 'outcome' => 'x']);
    }

    public function testDraftOfAnUnreviewedRecordIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        RecordReview::draft(new Control(['id' => 1]));
    }

    public function testRemovingAReviewKeepsTheDateAndTheHistory(): void
    {
        $risk = $this->risk();
        $review = RecordReview::record($risk, ['reviewed_on' => '2026-04-10', 'outcome' => 'Wrong risk.', 'next_review_date' => '2026-07-10']);
        $this->assertSame($risk->id, $review->getOwner()->id);
        $this->assertTrue($review->remove());
        $this->assertNull(RecordReview::findOne($review->id));
        $this->assertSame('2026-07-10', $risk->refresh() ? $risk->next_review_date : null);
        $log = AuditLog::findOne(['entity_type' => 'risk', 'entity_id' => $risk->id, 'action' => 'review_remove']);
        $this->assertStringContainsString('Wrong risk.', $log->getChangesArray()['review'][0]);
        $this->assertNull($log->getChangesArray()['review'][1]);
    }

    public function testHardDeleteTakesTheReviewsWithIt(): void
    {
        $decision = $this->decision();
        RecordReview::record($decision, ['reviewed_on' => '2026-05-20', 'outcome' => 'Fine.', 'next_review_date' => '2027-05-20']);
        $this->assertSame(1, (int) RecordReview::find()->where(['entity_type' => 'decision', 'entity_id' => $decision->id])->count());
        $decision->delete();
        $this->assertSame(0, (int) RecordReview::find()->where(['entity_type' => 'decision', 'entity_id' => $decision->id])->count());
    }
}
