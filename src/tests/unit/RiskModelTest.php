<?php

namespace tests\unit;

use app\models\AuditLog;
use app\models\Lookup;
use app\modules\risk\models\Risk;
use app\modules\risk\models\RiskSearch;
use tests\DbTestCase;

class RiskModelTest extends DbTestCase
{
    private function makeRisk(array $attrs = []): Risk
    {
        return new Risk($attrs + [
            'title' => 'Test risk',
            'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY),
            'owner_id' => $this->userId(),
            'status' => Risk::STATUS_ASSESSED,
            'date_identified' => '2026-01-15',
            'inherent_likelihood' => 4,
            'inherent_impact' => 4,
        ]);
    }

    public function testRiskRegisterListsAllRisksByDefault(): void
    {
        $open = $this->makeRisk();
        $this->assertTrue($open->save(), json_encode($open->getErrors()));
        $closed = $this->makeRisk(['status' => Risk::STATUS_CLOSED]);
        $this->assertTrue($closed->save(), json_encode($closed->getErrors()));

        $ids = static fn(array $params) => array_map('intval', (new RiskSearch())->search($params)->query->select('risk.id')->column());
        $this->assertSame('all', (new RiskSearch())->filter);
        $this->assertContains($open->id, $ids([]));
        $this->assertContains($closed->id, $ids([]));
        $this->assertContains($open->id, $ids(['filter' => 'open']));
        $this->assertNotContains($closed->id, $ids(['filter' => 'open']));
    }

    public function testResidualMayNotExceedInherent(): void
    {
        $risk = $this->makeRisk(['inherent_likelihood' => 2, 'inherent_impact' => 3, 'residual_likelihood' => 3, 'residual_impact' => 3]);
        $this->assertFalse($risk->validate());
        $this->assertArrayHasKey('residual_impact', $risk->getErrors());
        $this->assertStringContainsString('cannot exceed', $risk->getFirstError('residual_impact'));
    }

    public function testResidualEqualToInherentIsAllowed(): void
    {
        $risk = $this->makeRisk(['residual_likelihood' => 4, 'residual_impact' => 4]);
        $this->assertTrue($risk->validate(), json_encode($risk->getErrors()));
    }

    public function testResidualRequiresBothAxes(): void
    {
        $risk = $this->makeRisk(['residual_likelihood' => 2]);
        $this->assertFalse($risk->validate());
        $this->assertArrayHasKey('residual_impact', $risk->getErrors());
    }

    public function testScoresAreComputedAndStoredOnSave(): void
    {
        $risk = $this->makeRisk(['inherent_likelihood' => 5, 'inherent_impact' => 4, 'residual_likelihood' => 2, 'residual_impact' => 4]);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        $risk->refresh();
        $this->assertSame(20, (int) $risk->inherent_score);
        $this->assertSame(8, (int) $risk->residual_score);
        $this->assertSame('critical', $risk->getInherentRating());
        $this->assertSame('medium', $risk->getResidualRating());
        $this->assertSame(8, $risk->getCurrentScore());
    }

    public function testTargetMayNotExceedResidual(): void
    {
        $risk = $this->makeRisk(['residual_likelihood' => 2, 'residual_impact' => 3, 'target_likelihood' => 3, 'target_impact' => 3]);
        $this->assertFalse($risk->validate());
        $this->assertStringContainsString('cannot exceed the residual score (6)', $risk->getFirstError('target_impact'));
    }

    public function testTargetIsCheckedAgainstInherentWhenResidualIsNotAssessed(): void
    {
        $risk = $this->makeRisk(['inherent_likelihood' => 2, 'inherent_impact' => 2, 'target_likelihood' => 3, 'target_impact' => 2]);
        $this->assertFalse($risk->validate());
        $this->assertStringContainsString('cannot exceed the inherent score (4)', $risk->getFirstError('target_impact'));

        $risk = $this->makeRisk(['target_likelihood' => 2, 'target_impact' => 3]);
        $this->assertTrue($risk->validate(), json_encode($risk->getErrors()));
    }

    public function testTargetRequiresBothAxes(): void
    {
        $risk = $this->makeRisk(['target_impact' => 2]);
        $this->assertFalse($risk->validate());
        $this->assertArrayHasKey('target_likelihood', $risk->getErrors());
    }

    public function testTargetScoreIsStoredAndClearedOnSave(): void
    {
        $risk = $this->makeRisk(['residual_likelihood' => 4, 'residual_impact' => 4, 'target_likelihood' => 2, 'target_impact' => 3]);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        $risk->refresh();
        $this->assertSame(6, (int) $risk->target_score);
        $this->assertSame('medium', $risk->getTargetRating());
        $this->assertSame(16, $risk->getCurrentScore(), 'the target never counts as the current score');

        $risk->target_likelihood = '';
        $risk->target_impact = '';
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        $risk->refresh();
        $this->assertNull($risk->target_score);
    }

    public function testAcceptedRiskRequiresJustificationAndStampsAcceptance(): void
    {
        $risk = $this->makeRisk(['status' => Risk::STATUS_ACCEPTED]);
        $this->assertFalse($risk->validate());
        $this->assertArrayHasKey('acceptance_justification', $risk->getErrors());

        $risk->acceptance_justification = 'Within appetite';
        $risk->accepted_by_id = $this->userId();
        $this->assertTrue($risk->validate(), json_encode($risk->getErrors()));
        $this->assertSame(date('Y-m-d'), $risk->accepted_at, 'accepted date defaults to today');
        $this->assertSame(Risk::TREATMENT_ACCEPT, $risk->treatment);
    }

    public function testReferenceIsGeneratedOnInsert(): void
    {
        $risk = $this->makeRisk();
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        $this->assertMatchesRegularExpression('/^RSK-\d{4,}$/', $risk->reference);

        $second = $this->makeRisk(['title' => 'Second']);
        $second->save();
        $this->assertSame(
            (int) substr($risk->reference, 4) + 1,
            (int) substr($second->reference, 4)
        );
    }

    public function testOverdueFlags(): void
    {
        $risk = $this->makeRisk(['next_review_date' => '2026-01-01', 'target_treatment_date' => '2026-01-01', 'status' => Risk::STATUS_IN_TREATMENT]);
        $this->assertTrue($risk->isReviewOverdue('2026-02-01'));
        $this->assertTrue($risk->isTreatmentOverdue('2026-02-01'));
        $this->assertFalse($risk->isReviewOverdue('2025-12-31'));

        $risk->status = Risk::STATUS_ACCEPTED;
        $this->assertFalse($risk->isTreatmentOverdue('2026-02-01'), 'accepted risks have no pending treatment');
        $risk->status = Risk::STATUS_CLOSED;
        $this->assertFalse($risk->isReviewOverdue('2026-02-01'), 'closed risks are not reviewed');
    }

    public function testAuditTrailRecordsCreateAndFieldDiff(): void
    {
        $risk = $this->makeRisk();
        $risk->save();
        $risk->status = Risk::STATUS_IN_TREATMENT;
        $risk->treatment = Risk::TREATMENT_MITIGATE;
        $risk->save();
        $risk->archive();

        $logs = AuditLog::find()->where(['entity_type' => 'risk', 'entity_id' => $risk->id])->orderBy('id')->all();
        $this->assertSame(['create', 'update', 'archive'], array_map(static fn($l) => $l->action, $logs));
        $changes = $logs[1]->getChangesArray();
        $this->assertSame([Risk::STATUS_ASSESSED, Risk::STATUS_IN_TREATMENT], $changes['status']);
        $this->assertArrayNotHasKey('title', $changes, 'unchanged fields are not logged');
        $rows = $logs[1]->getFormattedChanges();
        $this->assertContains('In Treatment', array_column($rows, 'new'));
    }
}
