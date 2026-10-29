<?php

namespace tests\unit;

use app\helpers\Badge;
use app\models\Attachment;
use app\models\AuditLog;
use app\models\Lookup;
use app\models\RecordNote;
use app\models\RecordReview;
use app\modules\cloud\models\CloudAccount;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use app\modules\supplier\components\CloudSuppliers;
use app\modules\supplier\components\SupplierCheck;
use app\modules\supplier\components\SupplierChecks;
use app\modules\supplier\components\SupplierPosture;
use app\modules\supplier\models\Supplier;
use app\modules\supplier\models\SupplierAssurance;
use DateTimeImmutable;
use tests\DbTestCase;
use tests\support\OutputCapture;
use Yii;
use yii\base\Event;

class SupplierTest extends DbTestCase
{
    private function makeSupplier(array $attrs = []): Supplier
    {
        $supplier = new Supplier($attrs + [
            'name' => 'Fabrikam Managed IT',
            'status' => Supplier::STATUS_ACTIVE,
            'criticality' => 'medium',
            'data_classification' => 'internal',
            'access_level' => Supplier::ACCESS_NONE,
        ]);
        $this->assertTrue($supplier->save(), json_encode($supplier->getErrors()));
        return $supplier;
    }

    /** A supplier-check control in force */
    private function makeCheck(array $attrs = []): Control
    {
        $control = new Control($attrs + [
            'title' => 'Supplier due diligence',
            'status' => Control::STATUS_IMPLEMENTED,
            'control_type' => 'preventive',
            'nature' => 'administrative',
            'supplier_check' => SupplierCheck::MANUAL,
        ]);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        return $control;
    }

    private function addAssurance(Supplier $supplier, string $type, ?string $expires, ?string $issued = null): SupplierAssurance
    {
        $a = new SupplierAssurance(['supplier_id' => $supplier->id, 'type' => $type, 'expires_on' => $expires, 'issued_on' => $issued]);
        $this->assertTrue($a->save(), json_encode($a->getErrors()));
        return $a;
    }

    private static function d(int $days, string $from = 'today'): string
    {
        return (new DateTimeImmutable($from))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    // ---- Tier -----------------------------------------------------------------

    public function testTierIsDerivedFromExposure(): void
    {
        $this->assertSame(['tier' => 3, 'reasons' => []], Supplier::deriveTier('low', 'public', 'none', false));
        $this->assertSame(['tier' => 2, 'reasons' => ['processes personal data']], Supplier::deriveTier('low', 'internal', 'none', true));
        $this->assertSame(['tier' => 2, 'reasons' => ['medium criticality', 'holds confidential data', 'user access to our systems']],
            Supplier::deriveTier('medium', 'confidential', 'user', false));
        $this->assertSame(['tier' => 1, 'reasons' => ['critical to operations', 'holds restricted data', 'privileged access to our systems']],
            Supplier::deriveTier('critical', 'restricted', 'privileged', true));
        $this->assertSame(1, Supplier::deriveTier('high', 'public', 'none', false)['tier']);
        $this->assertSame(1, Supplier::deriveTier('low', 'public', 'privileged', false)['tier']);

        $s = $this->makeSupplier(['criticality' => 'low', 'data_classification' => 'public']);
        $this->assertSame(3, (int) $s->tier);
        $this->assertSame('Tier 3: nothing raises it above Tier 3', $s->getTierExplanation());
        $this->assertSame('Tier 3 · Standard', $s->getTierLabel());
        $s->access_level = Supplier::ACCESS_PRIVILEGED;
        $this->assertTrue($s->save());
        $this->assertSame(1, (int) $s->tier, 'recomputed on every save');
        $this->assertSame('Tier 1: privileged access to our systems', $s->getTierExplanation());
        $this->assertSame('', Supplier::tierShortLabel(null));
        $this->assertSame('Tier 2', Supplier::tierShortLabel('2'));

        $badge = Badge::tier(1, 'Why');
        $this->assertStringContainsString('gw-badge--critical', $badge);
        $this->assertStringContainsString('title="Why"', $badge);
        $this->assertStringContainsString('gw-null', Badge::tier(null));
    }

    // ---- Validation, defaults, reviews ----------------------------------------

    public function testValidationAndDefaults(): void
    {
        $s = new Supplier(['name' => '', 'status' => 'nope', 'criticality' => 'x', 'data_classification' => 'y', 'access_level' => 'z',
            'website' => 'not a url', 'status_page_url' => 'ftp://example.com', 'contract_start' => '2026-10-01', 'contract_end' => '2026-09-01',
            'notice_days' => -1, 'breach_notice_hours' => 'soon', 'dpa_status' => 'maybe', 'right_to_audit' => 'perhaps',
            'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY), 'checks' => 'nope']);
        $this->assertFalse($s->validate());
        foreach (['name', 'status', 'criticality', 'data_classification', 'access_level', 'website', 'status_page_url', 'contract_end',
                     'notice_days', 'breach_notice_hours', 'dpa_status', 'right_to_audit', 'category_id'] as $attribute) {
            $this->assertTrue($s->hasErrors($attribute), $attribute);
        }
        $this->assertSame([], $s->checks, 'a non-array is dropped');

        $s = $this->makeSupplier(['website' => 'fabrikam.example', 'owner_id' => '', 'dpa_status' => '', 'notice_days' => '',
            'category_id' => $this->lookupId(Lookup::TYPE_SUPPLIER_CATEGORY), 'personal_data' => null]);
        $this->assertMatchesRegularExpression('/^SUP-\d{4}$/', $s->reference);
        $this->assertSame('https://fabrikam.example', $s->website, 'default scheme');
        $this->assertNull($s->dpa_status);
        $this->assertNull($s->notice_days);
        $this->assertFalse((bool) $s->personal_data);
        // Coming into service sets the first review one tier interval away
        $this->assertSame(2, (int) $s->tier);
        $this->assertSame(self::d(0, '+24 months'), $s->next_review_date);

        $dupe = new Supplier(['name' => 'Fabrikam Managed IT', 'status' => 'active', 'criticality' => 'low', 'data_classification' => 'public', 'access_level' => 'none']);
        $this->assertFalse($dupe->validate());
        $this->assertStringContainsString('already has this name', $dupe->getFirstError('name'));
        $s->archive();
        $this->assertTrue($dupe->validate(), 'archived suppliers do not block the name');

        $proposed = $this->makeSupplier(['name' => 'Tailspin', 'status' => Supplier::STATUS_PROPOSED]);
        $this->assertNull($proposed->next_review_date, 'not reviewed before it is in service');
        $this->assertFalse($proposed->isReviewable());
        $this->assertFalse($proposed->isInService());
        $proposed->status = Supplier::STATUS_ACTIVE;
        $proposed->save();
        $this->assertSame(self::d(0, '+24 months'), $proposed->next_review_date);
        $this->assertSame('Active', $proposed->getStatusLabel());
        $this->assertSame('No access to our systems', $proposed->getAccessLabel());
    }

    public function testReviewIntervalFollowsTheTier(): void
    {
        $critical = $this->makeSupplier(['criticality' => 'critical', 'next_review_date' => self::d(-3)]);
        $this->assertSame('+12 months', $critical->reviewInterval());
        $this->assertTrue($critical->isReviewOverdue());
        $this->assertSame([$critical->id], array_map('intval', Supplier::find()->active()->reviewOverdue()->select('id')->column()));
        $this->assertSame(self::d(0, '+12 months'), RecordReview::draft($critical)->next_review_date);
        $review = RecordReview::record($critical, ['reviewed_on' => self::d(0), 'outcome' => 'SOC 2 read; fine.', 'next_review_date' => self::d(0, '+12 months')]);
        $this->assertFalse($review->hasErrors(), json_encode($review->getErrors()));
        $this->assertFalse(Supplier::findOne($critical->id)->isReviewOverdue());
        $this->assertTrue(RecordReview::takesReviews('supplier'));

        $low = $this->makeSupplier(['name' => 'Printer lease', 'criticality' => 'low', 'data_classification' => 'public']);
        $this->assertSame('+36 months', $low->reviewInterval());
        $saved = Yii::$app->params['supplier.reviewMonths'] ?? null;
        Yii::$app->params['supplier.reviewMonths'] = [1 => 6, 2 => 12, 3 => 18];
        try {
            $this->assertSame('+18 months', $low->reviewInterval());
            $this->assertSame(12, Supplier::reviewMonths(9), 'unknown tier');
        } finally {
            Yii::$app->params['supplier.reviewMonths'] = $saved;
        }
        $low->status = Supplier::STATUS_TERMINATED;
        $low->next_review_date = self::d(-1);
        $low->save();
        $this->assertFalse($low->isReviewOverdue(), 'terminated suppliers are not reviewed');
    }

    // ---- Contract -------------------------------------------------------------

    public function testRenewalNoticeWindow(): void
    {
        $today = date('Y-m-d');
        $none = $this->makeSupplier(['name' => 'No contract']);
        $this->assertNull($none->getNoticeDeadline());
        $this->assertFalse($none->isRenewalDue());

        $closing = $this->makeSupplier(['name' => 'Closing', 'contract_end' => self::d(80), 'notice_days' => 30]);
        $this->assertSame(self::d(50), $closing->getNoticeDeadline());
        $this->assertTrue($closing->isRenewalDue($today));
        $missed = $this->makeSupplier(['name' => 'Missed', 'contract_end' => self::d(10), 'notice_days' => 30]);
        $this->assertTrue($missed->isRenewalDue($today), 'notice deadline passed but the contract has not ended');
        $later = $this->makeSupplier(['name' => 'Later', 'contract_end' => self::d(200), 'notice_days' => 30]);
        $this->assertFalse($later->isRenewalDue($today));
        $ended = $this->makeSupplier(['name' => 'Ended', 'contract_end' => self::d(-1)]);
        $this->assertFalse($ended->isRenewalDue($today));
        $noNotice = $this->makeSupplier(['name' => 'No notice', 'contract_end' => self::d(20)]);
        $this->assertSame(self::d(20), $noNotice->getNoticeDeadline());
        $this->assertTrue($noNotice->isRenewalDue($today));
        $terminated = $this->makeSupplier(['name' => 'Terminated', 'status' => Supplier::STATUS_TERMINATED, 'contract_end' => self::d(20)]);
        $this->assertFalse($terminated->isRenewalDue($today));

        $expected = [$closing->id, $missed->id, $noNotice->id];
        sort($expected);
        $this->assertSame($expected, array_map('intval', Supplier::find()->active()->renewalDue($today)->select('supplier.id')->orderBy('supplier.id')->column()));
        $this->assertSame(60, Supplier::renewalWarningDays());
    }

    // ---- Assurance ------------------------------------------------------------

    public function testAssuranceLifecycleAndExpiry(): void
    {
        $s = $this->makeSupplier();
        $bad = new SupplierAssurance(['supplier_id' => $s->id, 'type' => 'nope', 'issued_on' => self::d(5), 'expires_on' => self::d(-5)]);
        $this->assertFalse($bad->validate());
        $this->assertTrue($bad->hasErrors('type'));
        $this->assertTrue($bad->hasErrors('issued_on'));
        $bad = new SupplierAssurance(['supplier_id' => $s->id, 'type' => 'iso27001', 'issued_on' => self::d(-5), 'expires_on' => self::d(-10)]);
        $this->assertFalse($bad->validate());
        $this->assertStringContainsString('after the issue date', $bad->getFirstError('expires_on'));

        $old = $this->addAssurance($s, 'iso27001', self::d(-30), self::d(-400));
        $this->assertTrue($old->isExpired());
        $this->assertFalse($old->expiresSoon());
        $this->assertSame([$old->id], array_map(static fn($a) => $a->id, Supplier::findOne($s->id)->getAssuranceDue()));
        // Renewed: the new certificate outlasts the old one, so nothing is due
        $new = $this->addAssurance($s, 'iso27001', self::d(300), self::d(-1));
        $this->assertTrue($new->outlasts($old));
        $this->assertSame([], Supplier::findOne($s->id)->getAssuranceDue());
        $soc = $this->addAssurance($s, 'soc2_type2', self::d(20));
        $this->assertTrue($soc->expiresSoon());
        $this->assertSame([$soc->id], array_map(static fn($a) => $a->id, Supplier::findOne($s->id)->getAssuranceDue()));
        $forever = $this->addAssurance($s, 'questionnaire', null);
        $this->assertFalse($forever->isExpired());
        $this->assertFalse($forever->expiresSoon());
        $this->assertTrue($forever->outlasts($new));
        $this->assertSame('Security questionnaire · no expiry date', $forever->getAuditText());
        $this->assertSame('SOC 2 Type II report', $soc->getTypeLabel());
        $this->assertSame($s->id, $soc->supplier->id);
        $this->assertSame(60, SupplierAssurance::expiryWarningDays());
        $this->assertSame([$forever->id, $new->id, $soc->id, $old->id], array_map(static fn($a) => $a->id, Supplier::findOne($s->id)->assurances), 'no expiry first, then latest');

        $other = $this->makeSupplier(['name' => 'Other']);
        $this->addAssurance($other, 'cyber_essentials', self::d(200));
        $this->assertSame([$s->id], array_map('intval', Supplier::find()->assuranceDue()->select('supplier.id')->column()));

        $actions = AuditLog::find()->select('action')->where(['entity_type' => 'supplier', 'entity_id' => $s->id])->orderBy('id')->column();
        $this->assertSame(['create', 'assurance', 'assurance', 'assurance', 'assurance'], $actions);
        $soc->delete();
        $log = AuditLog::find()->where(['entity_type' => 'supplier', 'entity_id' => $s->id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertSame('assurance_remove', $log->action);
        $this->assertStringContainsString('SOC 2 Type II report', $log->changes);
        $this->assertSame('Assurance added', AuditLog::ACTION_LABELS['assurance']);
    }

    // ---- Posture --------------------------------------------------------------

    public function testPostureEvaluation(): void
    {
        $manual = new SupplierCheck(1, 'CTL-0001', 'Due diligence', SupplierCheck::MANUAL);
        $dpa = new SupplierCheck(2, 'CTL-0002', 'DPA', SupplierCheck::DPA);
        $assurance = new SupplierCheck(3, 'CTL-0003', 'Assurance', SupplierCheck::ASSURANCE, [1, 2]);
        $checks = [1 => $manual, 2 => $dpa, 3 => $assurance];
        $today = '2026-10-29';

        // Tier 3 without personal data: only the manual check applies
        $p = SupplierPosture::evaluate($checks, 3, false, null, [], [1 => 'pass'], $today);
        $this->assertSame([1], array_keys($p['items']));
        $this->assertSame(SupplierPosture::STATE_COMPLIANT, $p['state']);
        $this->assertTrue($p['compliant']);

        $p = SupplierPosture::evaluate($checks, 1, true, 'not_signed', [], [1 => 'fail'], $today);
        $this->assertSame(SupplierPosture::STATE_NON_COMPLIANT, $p['state']);
        $this->assertSame(['Due diligence failed', 'No data processing agreement signed'], array_values($p['failures']));
        $this->assertSame(['No assurance recorded'], array_values($p['unknown']));

        $p = SupplierPosture::evaluate($checks, 2, true, null, [['label' => 'ISO', 'expires_on' => '2026-01-01'], ['label' => 'SOC 2', 'expires_on' => '2026-06-30']], ['1' => 'bogus'], $today);
        $this->assertSame(SupplierPosture::STATE_NON_COMPLIANT, $p['state']);
        $this->assertSame('Assurance expired: SOC 2 on 30 Jun 2026', $p['failures'][3]);
        $this->assertSame(['Due diligence not assessed', 'Data processing agreement not recorded'], array_values($p['unknown']));

        $p = SupplierPosture::evaluate($checks, 1, true, 'signed', [['label' => 'ISO', 'expires_on' => '2027-01-01'], ['label' => 'Questionnaire', 'expires_on' => null]], [1 => 'pass'], $today);
        $this->assertSame(SupplierPosture::STATE_COMPLIANT, $p['state']);
        $this->assertSame('Questionnaire (no expiry date)', $p['items'][3]['detail']);
        $this->assertSame('Data processing agreement signed', $p['items'][2]['detail']);
        $p = SupplierPosture::evaluate([3 => $assurance], 1, false, null, [['label' => 'ISO', 'expires_on' => '2027-01-01'], ['label' => 'CE', 'expires_on' => '2026-12-01']], [], $today);
        $this->assertSame('ISO until 1 Jan 2027', $p['items'][3]['detail']);
        $p = SupplierPosture::evaluate([1 => $manual], 2, false, null, [], [], $today);
        $this->assertSame(SupplierPosture::STATE_NOT_ASSESSED, $p['state']);

        $this->assertTrue($dpa->isDpa() && $assurance->isAssurance() && $manual->isManual());
        $this->assertFalse($assurance->appliesTo(3, true));
        $this->assertTrue($assurance->appliesTo(2, false));
    }

    public function testChecksControlsAndRecordedResults(): void
    {
        $this->assertSame([], SupplierChecks::all());
        $manual = $this->makeCheck(['supplierTierList' => ['1', '2']]);
        $this->assertSame('1,2', $manual->supplier_tiers);
        $this->assertSame([1, 2], $manual->getSupplierTiers());
        $this->assertSame('Tier 1, Tier 2 suppliers', $manual->getSupplierScopeLabel());
        $this->assertSame('Tier 1, Tier 2', $manual->formatAuditValue('supplier_tiers', '1,2'));
        $manual->supplierTierList = ['1', '2', '3'];
        $manual->save();
        $this->assertNull($manual->supplier_tiers, 'all tiers ticked = every tier');
        $this->assertSame('All suppliers', $manual->getSupplierScopeLabel());
        $dpa = $this->makeCheck(['title' => 'DPA', 'supplier_check' => SupplierCheck::DPA, 'supplierTierList' => '']);
        $this->assertSame('All suppliers processing personal data', $dpa->getSupplierScopeLabel());
        $planned = $this->makeCheck(['title' => 'Planned', 'status' => Control::STATUS_PLANNED]);
        $bad = new Control(['title' => 'Bad', 'status' => 'planned', 'control_type' => 'preventive', 'nature' => 'technical',
            'supplier_check' => 'nope', 'supplierTierList' => ['7']]);
        $this->assertFalse($bad->validate());
        $this->assertTrue($bad->hasErrors('supplier_check'));
        $this->assertTrue($bad->hasErrors('supplierTierList'));

        // Saving a control resets the cache: in force, in reference order; planned ones are not checks yet
        $this->assertSame([$manual->id, $dpa->id], array_keys(SupplierChecks::all()));
        $this->assertSame([$manual->id], array_keys(SupplierChecks::manual()));
        $this->assertNull(SupplierChecks::find($planned->id));
        $this->assertSame('Supplier due diligence', SupplierChecks::find($manual->id)->title);
        $this->assertContains($planned->id, array_map('intval', Control::find()->supplierChecks()->select('id')->column()));

        $fresh = new Control();
        $fresh->populateLinks();
        $this->assertSame([], $fresh->supplierTierList);
        $loaded = Control::findOne($dpa->id);
        $loaded->supplier_tiers = '2';
        $loaded->populateLinks();
        $this->assertSame(['2'], $loaded->supplierTierList);
        // Dropping the check drops its scope
        $loaded->supplierTierList = ['2'];
        $loaded->supplier_check = '';
        $this->assertTrue($loaded->save(), json_encode($loaded->getErrors()));
        $this->assertNull($loaded->supplier_check);
        $this->assertNull($loaded->supplier_tiers);

        // Results recorded through the form, cleared with '' and audited
        $s = $this->makeSupplier(['checks' => [$manual->id => 'fail']]);
        $this->assertSame([$manual->id => 'fail'], $s->getCheckResults());
        $this->assertSame(SupplierPosture::STATE_NON_COMPLIANT, $s->getPosture()['state']);
        $s->checks = [$manual->id => 'pass'];
        $s->save();
        $this->assertSame('pass', Supplier::findOne($s->id)->getCheckResults()[$manual->id]);
        $log = AuditLog::find()->where(['entity_type' => 'supplier', 'entity_id' => $s->id, 'action' => 'update'])->one();
        $this->assertStringContainsString($manual->reference, $log->changes);
        $invalid = Supplier::findOne($s->id);
        $invalid->checks = [$dpa->id => 'pass'];
        $this->assertFalse($invalid->validate(), 'only manual checks are recorded');
        $this->assertSame('Invalid check result.', $invalid->getFirstError('checks'));
        $form = Supplier::findOne($s->id);
        $form->populateLinks();
        $this->assertSame([$manual->id => 'pass'], $form->checks);
    }

    public function testPostureQueriesMatchPhpLogic(): void
    {
        $today = date('Y-m-d');
        $manual = $this->makeCheck(['supplierTierList' => ['1']]);
        $dpa = $this->makeCheck(['title' => 'DPA', 'supplier_check' => SupplierCheck::DPA]);
        $assurance = $this->makeCheck(['title' => 'Assurance', 'supplier_check' => SupplierCheck::ASSURANCE, 'supplierTierList' => ['1', '2']]);

        $suppliers = [
            $this->makeSupplier(['name' => 'T1 all good', 'criticality' => 'high', 'personal_data' => true, 'dpa_status' => 'signed', 'checks' => [$manual->id => 'pass']]),
            $this->makeSupplier(['name' => 'T1 failing', 'criticality' => 'high', 'checks' => [$manual->id => 'fail']]),
            $this->makeSupplier(['name' => 'T2 no DPA', 'personal_data' => true, 'dpa_status' => 'not_signed']),
            $this->makeSupplier(['name' => 'T2 DPA unknown', 'personal_data' => true]),
            $this->makeSupplier(['name' => 'T2 expired', 'data_classification' => 'confidential']),
            $this->makeSupplier(['name' => 'T3 nothing applies', 'criticality' => 'low']),
        ];
        $this->addAssurance($suppliers[0], 'iso27001', self::d(100));
        $this->addAssurance($suppliers[2], 'iso27001', null);
        $this->addAssurance($suppliers[3], 'iso27001', self::d(10));
        $this->addAssurance($suppliers[4], 'iso27001', self::d(-10));

        $ids = static fn($query) => array_map('intval', $query->select('supplier.id')->orderBy('supplier.id')->column());
        $expected = [];
        foreach ($suppliers as $s) {
            $expected[Supplier::findOne($s->id)->getPosture($today)['state']][] = $s->id;
        }
        $this->assertSame($expected[SupplierPosture::STATE_COMPLIANT], $ids(Supplier::find()->compliant($today)));
        $this->assertSame($expected[SupplierPosture::STATE_NON_COMPLIANT], $ids(Supplier::find()->nonCompliant($today)));
        $this->assertSame($expected[SupplierPosture::STATE_NOT_ASSESSED], $ids(Supplier::find()->notAssessed($today)));
        $this->assertSame([$suppliers[0]->id, $suppliers[5]->id], $expected[SupplierPosture::STATE_COMPLIANT]);
        $this->assertSame([$suppliers[3]->id], $expected[SupplierPosture::STATE_NOT_ASSESSED]);

        foreach (SupplierChecks::all() as $check) {
            foreach ([SupplierPosture::RESULT_PASS, SupplierPosture::RESULT_FAIL, SupplierPosture::RESULT_UNKNOWN] as $result) {
                $php = [];
                foreach ($suppliers as $s) {
                    $item = Supplier::findOne($s->id)->getPosture($today)['items'][$check->id] ?? null;
                    if ($item && $item['result'] === $result) {
                        $php[] = $s->id;
                    }
                }
                $this->assertSame($php, $ids(Supplier::find()->checkResult($check, $result, $today)), "$check->title $result");
            }
        }
        $this->assertSame([$suppliers[1]->id], $ids(Supplier::find()->checkResult(SupplierChecks::find($manual->id), 'fail')));
        $this->assertSame([], $ids(Supplier::find()->onboarding()));
        $this->assertCount(6, $ids(Supplier::find()->inService()));
    }

    public function testQueriesWithoutChecks(): void
    {
        $s = $this->makeSupplier();
        $this->assertSame(1, (int) Supplier::find()->compliant()->count());
        $this->assertSame(0, (int) Supplier::find()->nonCompliant()->count());
        $this->assertSame(0, (int) Supplier::find()->notAssessed()->count());
        $this->assertSame(SupplierPosture::STATE_COMPLIANT, $s->getPosture()['state']);
        $this->assertSame([], $s->getPosture()['items']);
    }

    // ---- Links ----------------------------------------------------------------

    public function testLinksFromOtherRecordsAndCloudAccounts(): void
    {
        $s = $this->makeSupplier(['name' => 'Amazon Web Services']);
        $risk = new Risk(['title' => 'Cloud outage', 'category_id' => $this->lookupId(Lookup::TYPE_RISK_CATEGORY), 'owner_id' => $this->userId(),
            'status' => Risk::STATUS_ASSESSED, 'date_identified' => '2026-10-01', 'inherent_likelihood' => 2, 'inherent_impact' => 4, 'supplierIds' => [$s->id]]);
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        $incident = new Incident(['title' => 'Region outage', 'category_id' => $this->lookupId(Lookup::TYPE_INCIDENT_CATEGORY), 'severity' => 'medium',
            'status' => 'new', 'detected_at' => '2026-10-20 10:00:00', 'supplierIds' => [$s->id]]);
        $this->assertTrue($incident->save(), json_encode($incident->getErrors()));
        $decision = new Decision(['title' => 'Use AWS', 'decision_date' => '2026-10-21', 'decision_text' => 'Host on AWS.', 'decision_makers' => 'CTO',
            'status' => Decision::STATUS_APPROVED, 'category_id' => $this->lookupId(Lookup::TYPE_DECISION_CATEGORY), 'supplierIds' => [$s->id]]);
        $this->assertTrue($decision->save(), json_encode($decision->getErrors()));
        $control = $this->makeCheck(['title' => 'Backups to S3', 'supplier_check' => null, 'supplierIds' => [$s->id]]);
        $bad = new Risk(['supplierIds' => [999999]]);
        $bad->validate(['supplierIds']);
        $this->assertTrue($bad->hasErrors('supplierIds'));

        $s = Supplier::findOne($s->id);
        $this->assertSame([$risk->id], array_map(static fn($r) => $r->id, $s->risks));
        $this->assertSame([$incident->id], array_map(static fn($r) => $r->id, $s->incidents));
        $this->assertSame([$decision->id], array_map(static fn($r) => $r->id, $s->decisions));
        $this->assertSame([$control->id], array_map(static fn($r) => $r->id, $s->controls));
        foreach ([$risk, $incident, $decision, $control] as $record) {
            $this->assertSame([$s->id], array_map(static fn($r) => $r->id, $record::findOne($record->id)->suppliers));
        }
        $this->assertSame('Suppliers involved', $incident->getAttributeLabel('supplierIds'));
        $this->assertSame('Suppliers it relies on', $control->getAttributeLabel('supplierIds'));
        $log = AuditLog::find()->where(['entity_type' => 'risk', 'entity_id' => $risk->id, 'action' => 'create'])->one();
        $this->assertStringContainsString($s->reference, $log->changes);

        $account = new CloudAccount(['name' => 'Production (AWS)', 'provider' => 'aws', 'environment' => 'production', 'status' => 'active',
            'criticality' => 'high', 'data_classification' => 'confidential', 'supplier_id' => 999999]);
        $this->assertFalse($account->validate());
        $this->assertTrue($account->hasErrors('supplier_id'));
        $account->supplier_id = '';
        $this->assertTrue($account->save(), json_encode($account->getErrors()));
        $this->assertNull($account->supplier_id);
        $account->supplier_id = $s->id;
        $this->assertTrue($account->save());
        $this->assertSame($s->id, $account->supplier->id);
        $this->assertSame([$account->id], array_map(static fn($a) => $a->id, Supplier::findOne($s->id)->cloudAccounts));
        $this->assertSame($s->getAuditLabel(), $account->formatAuditValue('supplier_id', $s->id));
        $this->assertSame('#999999', $account->formatAuditValue('supplier_id', 999999));
        $this->assertSame('Production', $account->formatAuditValue('environment', 'production'));
    }

    public function testRegistriesKnowSuppliers(): void
    {
        $s = $this->makeSupplier();
        $this->assertSame(Supplier::class, AuditLog::entities()['supplier'][1]);
        $this->assertContains('supplier', Attachment::ENTITY_TYPES);
        $this->assertTrue(RecordNote::takesNotes('supplier'));
        $this->assertSame('Supplier categories', Lookup::types()[Lookup::TYPE_SUPPLIER_CATEGORY]);
        $this->assertSame([['supplier', 'category_id']], Lookup::usages()[Lookup::TYPE_SUPPLIER_CATEGORY]);
        $this->assertGreaterThanOrEqual(10, count(Lookup::options(Lookup::TYPE_SUPPLIER_CATEGORY)), 'seeded by the migration');
        $this->assertSame('/supplier/supplier/view', Supplier::viewRoute());
        $this->assertSame('Supplier', Supplier::entityLabel());
        $this->assertSame('Fabrikam Managed IT', $s->getTitleValue());
        $this->assertSame('Yes', $s->formatAuditValue('personal_data', 1));
        $this->assertSame('Tier 2 · Important', $s->formatAuditValue('tier', 2));

        // Hard delete takes notes, reviews and files with it
        RecordNote::add($s, 'Spoke to their account manager.');
        $s->archive();
        $s->delete();
        $this->assertSame(0, (int) RecordNote::find()->where(['entity_type' => 'supplier', 'entity_id' => $s->id])->count());
    }

    // ---- Suppliers from cloud accounts ------------------------------------------

    public function testSuppliersFromCloudAccounts(): void
    {
        $make = function (string $name, string $provider, string $criticality, string $classification, string $status = 'active') {
            $a = new CloudAccount(['name' => $name, 'provider' => $provider, 'environment' => 'production', 'status' => $status,
                'criticality' => $criticality, 'data_classification' => $classification]);
            $this->assertTrue($a->save(), json_encode($a->getErrors()));
        };
        $make('Workspace', 'google_workspace', 'high', 'confidential');
        $make('GCP prod', 'gcp', 'medium', 'restricted');
        $make('AWS prod', 'aws', 'critical', 'restricted');
        $make('AWS dev', 'aws', 'low', 'internal');
        $make('M365', 'm365', 'medium', 'internal');
        $make('Old GitHub', 'github', 'high', 'confidential', 'closed');
        $make('Something else', 'other', 'high', 'internal');
        $this->makeSupplier(['name' => 'Microsoft']);

        $results = CloudSuppliers::create($this->userId());
        $byName = [];
        foreach ($results as $r) {
            $byName[$r['supplier']->name] = $r;
        }
        $this->assertSame(['Google', 'Amazon Web Services', 'Microsoft'], array_keys($byName), 'one per vendor, in-service accounts only, Other skipped');
        $this->assertSame(CloudSuppliers::EXISTS, $byName['Microsoft']['action']);

        $google = $byName['Google'];
        $this->assertSame(CloudSuppliers::CREATED, $google['action']);
        $this->assertSame(['Google Workspace', 'Google Cloud'], $google['providers']);
        $this->assertSame(2, $google['accounts']);
        $g = Supplier::findOne($google['supplier']->id);
        $this->assertSame('high', $g->criticality, 'highest of its accounts');
        $this->assertSame('restricted', $g->data_classification);
        $this->assertSame('https://cloud.google.com', $g->website, 'IaaS wins over SaaS');
        $this->assertSame('Cloud infrastructure (IaaS / PaaS)', $g->category->name);
        $this->assertSame(1, (int) $g->tier);
        $this->assertSame($this->userId(), (int) $g->owner_id);
        $this->assertStringContainsString('link its cloud accounts', $g->notes);
        $this->assertSame(0, (int) CloudAccount::find()->where(['not', ['supplier_id' => null]])->count(), 'accounts are not linked');
        $aws = Supplier::findOne($byName['Amazon Web Services']['supplier']->id);
        $this->assertSame('critical', $aws->criticality);
        $this->assertSame('Microsoft', CloudSuppliers::vendorName('m365'));
        $this->assertNull(CloudSuppliers::vendorName('other'));

        // A second run finds them all; an unknown owner fails validation
        $this->assertSame([CloudSuppliers::EXISTS], array_values(array_unique(array_column(CloudSuppliers::create(null), 'action'))));
        Supplier::deleteAll(['name' => 'Google']);
        $failed = array_values(array_filter(CloudSuppliers::create(999999), static fn($r) => $r['action'] !== CloudSuppliers::EXISTS));
        $this->assertSame(CloudSuppliers::FAILED, $failed[0]['action']);
        $this->assertNotEmpty($failed[0]['errors']);
    }

    public function testFromCloudCommand(): void
    {
        [$code, $out] = OutputCapture::run('supplier/from-cloud', ['user' => 'nobody-here']);
        $this->assertSame(65, $code);
        $this->assertStringContainsString("User 'nobody-here' not found", $out);

        [$code, $out] = OutputCapture::run('supplier/from-cloud');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing to create', $out);

        $a = new CloudAccount(['name' => 'AWS prod', 'provider' => 'aws', 'environment' => 'production', 'status' => 'active',
            'criticality' => 'high', 'data_classification' => 'confidential']);
        $this->assertTrue($a->save());
        $user = \app\models\User::findOne($this->userId());
        [$code, $out] = OutputCapture::run('supplier/from-cloud', ['user' => $user->username, '_aliases' => ['n' => 1]]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Dry run (nothing saved): 1 created', $out);
        $this->assertFalse(Supplier::find()->exists());

        [$code, $out] = OutputCapture::run('supplier/from-cloud', ['user' => $user->username]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Amazon Web Services', $out);
        $this->assertStringContainsString('Link each cloud account', $out);
        $supplier = Supplier::findOne(['name' => 'Amazon Web Services']);
        $this->assertSame($user->id, (int) $supplier->created_by, 'recorded as --user');

        // A supplier that does not validate is reported and fails the run; an exception rolls everything back
        Supplier::deleteAll();
        $refuse = static function (\yii\base\ModelEvent $e) {
            $e->isValid = false;
        };
        Event::on(Supplier::class, Supplier::EVENT_BEFORE_VALIDATE, $refuse);
        try {
            [$code, $out] = OutputCapture::run('supplier/from-cloud');
        } finally {
            Event::off(Supplier::class, Supplier::EVENT_BEFORE_VALIDATE, $refuse);
        }
        $this->assertSame(65, $code);
        $this->assertStringContainsString('1 failed', $out);
        $boom = static function () {
            throw new \RuntimeException('disk full');
        };
        Event::on(Supplier::class, Supplier::EVENT_BEFORE_INSERT, $boom);
        try {
            [$code, $out] = OutputCapture::run('supplier/from-cloud');
        } finally {
            Event::off(Supplier::class, Supplier::EVENT_BEFORE_INSERT, $boom);
        }
        $this->assertSame(70, $code);
        $this->assertStringContainsString('Creating suppliers failed: disk full', $out);
        $this->assertFalse(Supplier::find()->exists());
    }
}
