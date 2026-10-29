<?php

namespace tests\unit;

use app\models\Lookup;
use app\modules\dashboard\widgets\OverdueReviewsKpi;
use app\modules\decision\models\Decision;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;
use tests\DbTestCase;
use Yii;

class OverdueReviewsKpiTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Start from no overdue reviews (rolled back with the test transaction)
        Yii::$app->db->createCommand()->update('risk', ['next_review_date' => null])->execute();
        Yii::$app->db->createCommand()->update('decision', ['review_date' => null])->execute();
        Yii::$app->db->createCommand()->update('supplier', ['next_review_date' => null])->execute();
    }

    private function overdueRisk(): void
    {
        $risk = new Risk([
            'title' => 'Overdue risk', 'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY),
            'owner_id' => $this->userId(), 'status' => Risk::STATUS_ASSESSED, 'date_identified' => '2026-01-15',
            'inherent_likelihood' => 3, 'inherent_impact' => 3,
        ]);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        Yii::$app->db->createCommand()->update('risk', ['next_review_date' => '2020-01-01'], ['id' => $risk->id])->execute();
    }

    private function overdueDecision(): void
    {
        $d = new Decision([
            'title' => 'Overdue decision', 'decision_date' => '2026-01-01', 'decision_text' => 'x', 'decision_makers' => 'me',
            'status' => Decision::STATUS_APPROVED, 'category_id' => $this->lookupId(Lookup::TYPE_DECISION_CATEGORY),
        ]);
        $this->assertTrue($d->save(), json_encode($d->getErrors()));
        Yii::$app->db->createCommand()->update('decision', ['review_date' => '2020-01-01'], ['id' => $d->id])->execute();
    }

    private function overdueSupplier(): void
    {
        $s = new Supplier(['name' => 'Overdue supplier', 'status' => Supplier::STATUS_ACTIVE, 'criticality' => 'low',
            'data_classification' => 'public', 'access_level' => Supplier::ACCESS_NONE]);
        $this->assertTrue($s->save(), json_encode($s->getErrors()));
        Yii::$app->db->createCommand()->update('supplier', ['next_review_date' => '2020-01-01'], ['id' => $s->id])->execute();
    }

    private function tile(): string
    {
        return OverdueReviewsKpi::widget();
    }

    public function testNothingOverdueLinksToRisks(): void
    {
        $html = $this->tile();
        $this->assertStringContainsString('gw-kpi--success', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*risk[^"]*review-overdue#', $html);
    }

    public function testOnlyDecisionsOverdueLinksToDecisions(): void
    {
        $this->overdueDecision();
        $html = $this->tile();
        $this->assertStringContainsString('gw-kpi--warning', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*decision[^"]*review-overdue#', $html);
    }

    public function testOnlyRisksOverdueLinksToRisks(): void
    {
        $this->overdueRisk();
        $html = $this->tile();
        $this->assertMatchesRegularExpression('#<a [^>]*class="gw-kpi [^"]*" href="[^"]*risk[^"]*review-overdue#', $html);
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*decision#', $html);
        $this->assertStringNotContainsString('gw-kpi__breakdown-link', $html);
    }

    public function testBothOverdueLinksEachBreakdownRow(): void
    {
        $this->overdueRisk();
        $this->overdueDecision();
        $html = $this->tile();
        // The tile is not a link (links cannot nest); each row links to its own list
        $this->assertMatchesRegularExpression('#^\s*<div [^>]*class="gw-kpi #', $html);
        $this->assertStringNotContainsString('gw-kpi__go', $html);
        $this->assertMatchesRegularExpression('#class="gw-kpi__breakdown-link" href="[^"]*risk[^"]*review-overdue#', $html);
        $this->assertMatchesRegularExpression('#class="gw-kpi__breakdown-link" href="[^"]*decision[^"]*review-overdue#', $html);
    }

    public function testOnlySuppliersOverdueLinksToSuppliers(): void
    {
        $this->overdueSupplier();
        $html = $this->tile();
        $this->assertMatchesRegularExpression('#<a [^>]*class="gw-kpi [^"]*" href="[^"]*supplier[^"]*review-overdue#', $html);
        $this->assertStringContainsString('Suppliers', $html);
    }

    public function testSuppliersAndRisksOverdueLinkEachRow(): void
    {
        $this->overdueRisk();
        $this->overdueSupplier();
        $html = $this->tile();
        $this->assertMatchesRegularExpression('#class="gw-kpi__breakdown-link" href="[^"]*supplier[^"]*review-overdue#', $html);
        $this->assertMatchesRegularExpression('#class="gw-kpi__breakdown-link" href="[^"]*risk[^"]*review-overdue#', $html);
        $this->assertDoesNotMatchRegularExpression('#breakdown-link" href="[^"]*decision#', $html, 'no link for a row with nothing overdue');
    }
}
