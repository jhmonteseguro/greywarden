<?php

namespace tests\functional;

use app\models\AuditLog;
use app\models\Lookup;
use app\models\RecordReview;
use app\models\User;
use app\modules\decision\models\Decision;
use app\modules\risk\models\Risk;
use tests\FunctionalTestCase;

/**
 * Recording and removing completed reviews of risks and decisions over HTTP:
 * the review date moving on, refused reviews coming back with their errors,
 * who may remove a review, and the review's place on the page and in the PDF.
 */
class ReviewFlowsTest extends FunctionalTestCase
{
    private function risk(): Risk
    {
        $risk = new Risk(['title' => 'Phishing', 'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY), 'owner_id' => $this->userIdFor('analyst'),
            'status' => Risk::STATUS_ASSESSED, 'date_identified' => '2026-01-01', 'inherent_likelihood' => 2, 'inherent_impact' => 2, 'next_review_date' => '2026-04-01']);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        return $risk;
    }

    public function testRecordingAReviewOfARisk(): void
    {
        $risk = $this->risk();
        $this->loginAs('analyst');

        $this->get("/risks/{$risk->id}");
        $this->assertSee('id="tab-reviews"');
        $this->assertSee('Reviews (0)');
        $this->assertSee('No reviews recorded yet.');
        $this->assertSee('id="review-form"');
        $this->assertSee('Last reviewed');
        $this->assertSee('Never');

        $this->post("/reviews/record?entity=risk&id={$risk->id}", ['RecordReview' => [
            'reviewed_on' => '2026-04-10', 'reviewed_by_id' => (string) $this->userIdFor('analyst'),
            'outcome' => 'Still valid; likelihood unchanged.', 'next_review_date' => '2026-07-10',
        ]]);
        $this->assertRedirect("/risks/{$risk->id}");
        $this->assertStringStartsWith("Review of {$risk->reference} recorded. Next review ", $this->flash('success'));
        $this->assertSame('2026-07-10', $risk->refresh() ? $risk->next_review_date : null);
        $review = RecordReview::latestFor($risk);
        $this->assertSame($this->userIdFor('analyst'), (int) $review->reviewed_by_id);
        $this->assertSame('2026-04-01', $review->due_on);

        $this->loginAs('viewer');
        $this->get("/risks/{$risk->id}");
        $this->assertSee('Reviews (1)');
        $this->assertSee('Still valid; likelihood unchanged.');
        $this->assertSee('Functional Analyst');
        $this->assertSee('Late');
        $this->assertNotSee('id="review-form"');
        $this->assertNotSee('/reviews/');
        $this->assertSee('Review recorded');

        $this->get("/risks/{$risk->id}/pdf");
        $this->assertOk();
        $this->assertStringStartsWith('%PDF-', $this->body);

        // Viewers cannot record reviews
        $this->post("/reviews/record?entity=risk&id={$risk->id}", ['RecordReview' => ['reviewed_on' => '2026-04-11', 'outcome' => 'x', 'next_review_date' => '2026-07-11']]);
        $this->assertStatus(403);
        $this->assertCount(1, RecordReview::listFor($risk));
    }

    public function testRefusedReviewsComeBackWithTheirErrors(): void
    {
        $risk = $this->risk();
        $this->loginAs('analyst');

        $this->post("/reviews/record?entity=risk&id={$risk->id}", ['RecordReview' => [
            'reviewed_on' => '2026-04-10', 'outcome' => 'Looked at it', 'next_review_date' => '2026-04-01', 'reviewed_by_id' => ['x'],
        ]]);
        $this->assertStringContainsString('The review was not recorded: ', $this->flash('danger'));
        $this->assertStringContainsString('The next review date must be after the review date.', $this->flash('danger'));
        $this->assertSame('2026-04-01', $risk->refresh() ? $risk->next_review_date : null);
        $this->assertSame([], RecordReview::listFor($risk));

        $this->get("/risks/{$risk->id}");
        $this->assertSee('Looked at it');
        $this->assertSee('The next review date must be after the review date.');
        $this->assertNull($_SESSION[RecordReview::draftKey($risk)] ?? null, 'the draft is offered once');
        $this->get("/risks/{$risk->id}");
        $this->assertNotSee('Looked at it');

        $this->post('/reviews/record?entity=control&id=1', ['RecordReview' => ['outcome' => 'x']]);
        $this->assertStatus(404, 'controls are tested, not reviewed');
        $this->post('/reviews/record?entity=risk&id=999999', ['RecordReview' => ['outcome' => 'x']]);
        $this->assertStatus(404);
    }

    public function testDecisionsAreReviewedTheSameWay(): void
    {
        $decision = new Decision(['title' => 'Adopt MFA', 'decision_date' => '2026-01-01', 'decision_text' => 'Everyone.', 'review_date' => '2026-12-01',
            'decision_makers' => 'CISO', 'status' => Decision::STATUS_APPROVED, 'category_id' => $this->lookupId(Lookup::TYPE_DECISION_CATEGORY)]);
        $this->assertTrue($decision->save(), json_encode($decision->getErrors()));
        $this->loginAs('analyst');

        $this->get("/decisions/{$decision->id}");
        $this->assertSee('id="tab-reviews"');
        $this->assertSee('Review due ');

        $this->post("/reviews/record?entity=decision&id={$decision->id}", ['RecordReview' => [
            'reviewed_on' => '2026-10-01', 'outcome' => 'Reaffirmed at the security forum.', 'next_review_date' => '2027-10-01',
        ]]);
        $this->assertRedirect("/decisions/{$decision->id}");
        $this->assertSame('2027-10-01', $decision->refresh() ? $decision->review_date : null);

        $this->get("/decisions/{$decision->id}");
        $this->assertSee('Reviews (1)');
        $this->assertSee('Reaffirmed at the security forum.');
        $this->get("/decisions/{$decision->id}/pdf");
        $this->assertOk();
        $this->assertStringStartsWith('%PDF-', $this->body);
    }

    public function testRemovingReviews(): void
    {
        $risk = $this->risk();
        $other = User::createUser('fn.analyst2', 'fn.analyst2@example.com', 'Second Analyst', self::PASSWORD, 'analyst', false);
        $url = "/reviews/record?entity=risk&id={$risk->id}";

        $this->loginAs('analyst');
        $this->post($url, ['RecordReview' => ['reviewed_on' => '2026-04-10', 'outcome' => 'Analyst review', 'next_review_date' => '2026-07-10']]);
        $own = RecordReview::latestFor($risk);
        // Recorded on behalf of someone else: the named reviewer may remove it too
        $this->post($url, ['RecordReview' => ['reviewed_on' => '2026-04-11', 'reviewed_by_id' => (string) $other->id, 'outcome' => 'Second analyst review', 'next_review_date' => '2026-07-11']]);
        $theirs = RecordReview::latestFor($risk);
        $this->get("/risks/{$risk->id}");
        $this->assertSee('Recorded by Functional Analyst');

        $this->loginAs('admin');
        $this->post($url, ['RecordReview' => ['reviewed_on' => '2026-04-12', 'outcome' => 'Admin review', 'next_review_date' => '2026-07-12']]);
        $admins = RecordReview::latestFor($risk);

        // An analyst may remove only reviews they recorded or carried out
        $this->loginAs('analyst');
        $this->get("/risks/{$risk->id}");
        $this->assertSee('/reviews/' . $own->id . '/remove');
        $this->assertNotSee('/reviews/' . $admins->id . '/remove');
        $this->post("/reviews/{$admins->id}/remove");
        $this->assertStatus(403);
        $this->post('/reviews/999999/remove');
        $this->assertStatus(404);

        $_SESSION = ['__id' => $other->id, '__authKey' => $other->auth_key];
        $this->post("/reviews/{$theirs->id}/remove");
        $this->assertRedirect("/risks/{$risk->id}");
        $this->assertNull(RecordReview::findOne($theirs->id));

        $this->loginAs('analyst');
        $this->post("/reviews/{$own->id}/remove");
        $this->assertStringContainsString('Review removed.', $this->flash('info'));
        $this->assertNull(RecordReview::findOne($own->id));
        $this->assertSame('2026-07-12', $risk->refresh() ? $risk->next_review_date : null, 'the review date stays');
        $this->assertSame(2, (int) AuditLog::find()->where(['entity_type' => 'risk', 'entity_id' => $risk->id, 'action' => 'review_remove'])->count());

        // Archived records are read-only
        $risk->archive();
        $this->post($url, ['RecordReview' => ['reviewed_on' => '2026-04-13', 'outcome' => 'Too late', 'next_review_date' => '2026-07-13']]);
        $this->assertStringContainsString('Archived records are read-only', $this->flash('warning'));
        $this->loginAs('admin');
        $this->post("/reviews/{$admins->id}/remove");
        $this->assertStringContainsString('Archived records are read-only', $this->flash('warning'));
        $this->assertNotNull(RecordReview::findOne($admins->id));
        $this->get("/risks/{$risk->id}");
        $this->assertSee('Admin review');
        $this->assertNotSee('id="review-form"');

        $risk->restore();
        $this->post("/reviews/{$admins->id}/remove");
        $this->assertNull(RecordReview::findOne($admins->id));
    }
}
