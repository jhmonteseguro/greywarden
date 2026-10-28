<?php

namespace tests\unit;

use app\models\AuditLog;
use app\models\Setting;
use app\modules\admin\models\ComplianceForm;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\components\FrameworkCoverage;
use app\modules\control\models\Control;
use app\modules\control\models\FrameworkRequirement;
use tests\DbTestCase;

/**
 * The SOC 2 catalogue, and which framework requirements are in scope: the
 * frameworks switched on in Admin › Settings and the SOC 2 categories chosen.
 */
class FrameworkScopeTest extends DbTestCase
{
    private function requirement(string $externalId): FrameworkRequirement
    {
        $r = FrameworkRequirement::findOne(['external_id' => $externalId]);
        $this->assertNotNull($r, "$externalId is not in the catalogue");
        return $r;
    }

    private function settings(array $values): void
    {
        Setting::put($values);
    }

    public function testSoc2CatalogueIsLoaded(): void
    {
        $count = static fn(array $where) => (int) FrameworkRequirement::find()->where(['framework' => FrameworkCatalogue::SOC2])->andWhere($where)->count();
        $this->assertSame(61, $count([]), '33 common criteria + 3 availability + 2 confidentiality + 5 processing integrity + 18 privacy');
        $this->assertSame(33, $count(['like', 'group_code', 'CC%', false]));
        $this->assertSame(18, $count(['like', 'group_code', 'P_', false]));
        $this->assertSame('CC6 Logical and physical access', $this->requirement('CC6.1')->group_name);
        $this->assertSame('Privacy: use, retention and disposal', $this->requirement('P4.3')->group_name);

        $this->assertSame('CC6', FrameworkCatalogue::groupCode(FrameworkCatalogue::SOC2, 'CC6.1'));
        $this->assertSame('PI1', FrameworkCatalogue::groupCode(FrameworkCatalogue::SOC2, 'PI1.3'));
        $this->assertSame('5', FrameworkCatalogue::groupCode(FrameworkCatalogue::ISO27001, 'A.5.12'));
        $this->assertSame('FW', FrameworkCatalogue::groupCode(FrameworkCatalogue::CYBER_ESSENTIALS, 'CE-FW-3'));
        foreach (['CC1' => 'security', 'A1' => 'availability', 'C1' => 'confidentiality', 'PI1' => 'processing_integrity', 'P8' => 'privacy', 'X1' => null] as $group => $category) {
            $this->assertSame($category, FrameworkCatalogue::soc2Category($group), $group);
        }

        $this->assertSame(FrameworkCatalogue::SOC2, FrameworkCatalogue::fromSlug('soc2'));
        $this->assertSame('SOC 2 readiness', FrameworkCatalogue::title(FrameworkCatalogue::SOC2));
        $this->assertSame('award', FrameworkCatalogue::icon(FrameworkCatalogue::SOC2));
        $this->assertStringContainsString('Trust Services Criteria', FrameworkCatalogue::version(FrameworkCatalogue::SOC2));
        foreach (['label', 'shortLabel', 'navLabel', 'title', 'slug'] as $method) {
            $this->assertSame('nope', FrameworkCatalogue::$method('nope'), "$method falls back to the key");
        }
        $this->assertSame('patch-check', FrameworkCatalogue::icon('nope'));
    }

    public function testFrameworksAreOnUntilSwitchedOff(): void
    {
        $this->assertSame(['iso27001', 'cyber_essentials', 'soc2'], FrameworkCatalogue::enabled());
        $this->assertFalse(FrameworkCatalogue::isEnabled('nope'));

        $this->settings(['framework.cyber_essentials' => '0', 'framework.soc2' => '1']);
        $this->assertSame(['iso27001', 'soc2'], FrameworkCatalogue::enabled());
        $this->assertSame([], FrameworkCoverage::rows(FrameworkCatalogue::CYBER_ESSENTIALS));
        $this->assertSame([], FrameworkRequirement::selectOptions(FrameworkCatalogue::CYBER_ESSENTIALS));
        $this->assertSame([], FrameworkRequirement::idsMatching('CE-UA'));
        $this->assertFalse($this->requirement('CE-UA-4')->getIsInScope());
        $this->assertTrue($this->requirement('A.8.5')->getIsInScope());

        $this->settings(['framework.iso27001' => '0', 'framework.soc2' => '0']);
        $this->assertSame([], FrameworkCatalogue::enabled());
        $this->assertSame(0, (int) FrameworkRequirement::find()->where(FrameworkCatalogue::scopeCondition())->count());
    }

    public function testSoc2ScopeFollowsTheChosenCategories(): void
    {
        $this->assertSame(['security'], FrameworkCatalogue::soc2Scope(), 'Security only until categories are added');
        $this->assertSame(['CC1', 'CC2', 'CC3', 'CC4', 'CC5', 'CC6', 'CC7', 'CC8', 'CC9'], FrameworkCatalogue::soc2Groups());
        $this->assertCount(33, FrameworkCoverage::rows(FrameworkCatalogue::SOC2));
        $this->assertFalse($this->requirement('A1.2')->getIsInScope());
        $this->assertArrayNotHasKey('Availability', FrameworkRequirement::selectOptions(FrameworkCatalogue::SOC2));

        // Stored order and unknown names do not matter; Security cannot be dropped
        $this->settings([FrameworkCatalogue::SETTING_SOC2_CATEGORIES => 'privacy,bogus,availability']);
        $this->assertSame(['security', 'availability', 'privacy'], FrameworkCatalogue::soc2Scope());
        $this->assertCount(33 + 3 + 18, FrameworkCoverage::rows(FrameworkCatalogue::SOC2));
        $this->assertTrue($this->requirement('A1.2')->getIsInScope());
        $this->assertTrue($this->requirement('P4.3')->getIsInScope());
        $this->assertFalse($this->requirement('C1.1')->getIsInScope());
        $this->assertArrayHasKey('Availability', FrameworkRequirement::selectOptions(FrameworkCatalogue::SOC2));
        $this->assertSame([$this->requirement('A1.2')->id], FrameworkRequirement::idsMatching('A1.2'));
        $this->assertSame([], FrameworkRequirement::idsMatching('C1.1'));
        $this->assertSame([], FrameworkRequirement::idsMatching('  '));

        $this->settings(['framework.soc2' => '0']);
        $this->assertFalse($this->requirement('CC6.1')->getIsInScope(), 'switched off overrides the categories');
    }

    public function testOutOfScopeLinksAreHiddenButKept(): void
    {
        $this->settings([FrameworkCatalogue::SETTING_SOC2_CATEGORIES => 'privacy']);
        $ids = array_map(fn($e) => $this->requirement($e)->id, ['A.8.5', 'CE-UA-4', 'CC6.1', 'P4.3']);
        $control = new Control(['title' => 'MFA everywhere', 'status' => Control::STATUS_IMPLEMENTED, 'implemented_on' => date('Y-m-d'),
            'control_type' => 'preventive', 'nature' => 'technical', 'requirementIds' => $ids]);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        $this->assertSame(['iso27001' => ['A.8.5'], 'cyber_essentials' => ['CE-UA-4'], 'soc2' => ['CC6.1', 'P4.3']], Control::findOne($control->id)->frameworkSummary());

        // Cyber Essentials off, Privacy out of SOC 2 scope
        $this->settings(['framework.cyber_essentials' => '0', FrameworkCatalogue::SETTING_SOC2_CATEGORIES => '']);
        $control = Control::findOne($control->id);
        $this->assertSame(['iso27001' => ['A.8.5'], 'soc2' => ['CC6.1']], $control->frameworkSummary());
        $this->assertSame([], $control->requirementsOf(FrameworkCatalogue::CYBER_ESSENTIALS));
        $this->assertSame([$ids[1], $ids[3]], FrameworkRequirement::idsOutOfScope($ids));
        $this->assertSame([], FrameworkRequirement::idsOutOfScope([]));

        // The form only posts what it shows: dropping CC6.1 must not drop the hidden links
        $control->requirementIds = [$ids[0]];
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        $linked = array_map('intval', $control->getRequirements()->select('id')->column());
        sort($linked);
        $this->assertSame([$ids[0], $ids[1], $ids[3]], $linked);

        $this->settings(['framework.cyber_essentials' => '1', FrameworkCatalogue::SETTING_SOC2_CATEGORIES => 'privacy']);
        $this->assertSame(['iso27001' => ['A.8.5'], 'cyber_essentials' => ['CE-UA-4'], 'soc2' => ['P4.3']], Control::findOne($control->id)->frameworkSummary());
        $this->assertSame(FrameworkCoverage::STATE_MET, FrameworkCoverage::rows(FrameworkCatalogue::CYBER_ESSENTIALS, [$ids[1]])[$ids[1]]['state'],
            'the kept link counts again once the framework is back');
    }

    public function testComplianceFormSavesChangesAndAuditsThem(): void
    {
        $form = ComplianceForm::current();
        $this->assertSame(['iso27001', 'cyber_essentials', 'soc2'], $form->frameworks);
        $this->assertSame([], $form->soc2_categories);
        $this->assertTrue($form->save(), 'saving unchanged settings is fine');
        $this->assertSame(0, (int) Setting::find()->count(), 'and stores nothing');
        $this->assertNull(AuditLog::findOne(['entity_type' => 'setting']));

        $form = new ComplianceForm(['frameworks' => ['iso27001', 'nist'], 'soc2_categories' => ['security']]);
        $this->assertFalse($form->save());
        $this->assertTrue($form->hasErrors('frameworks'));
        $this->assertTrue($form->hasErrors('soc2_categories'), 'Security is not optional');

        $form = new ComplianceForm(['frameworks' => '', 'soc2_categories' => '']);
        $this->assertTrue($form->save(), 'an empty checkbox list switches everything off');
        $this->assertSame([], FrameworkCatalogue::enabled());

        $form = new ComplianceForm(['frameworks' => ['soc2', 'iso27001'], 'soc2_categories' => ['privacy', 'availability']]);
        $this->assertTrue($form->save());
        $this->assertSame(['iso27001', 'soc2'], FrameworkCatalogue::enabled());
        $this->assertSame('availability,privacy', Setting::get(FrameworkCatalogue::SETTING_SOC2_CATEGORIES));
        $this->assertSame(['availability', 'privacy'], ComplianceForm::current()->soc2_categories);

        $entry = AuditLog::find()->where(['entity_type' => 'setting', 'entity_label' => 'Compliance frameworks'])->orderBy(['id' => SORT_DESC])->one();
        $rows = array_column($entry->getFormattedChanges(), null, 'attribute');
        $this->assertSame(['ISO/IEC 27001:2022', 'Off', 'On'], [$rows['iso27001']['field'], $rows['iso27001']['old'], $rows['iso27001']['new']]);
        $this->assertSame(['SOC 2 categories in scope', 'Security', 'Security, Availability, Privacy'],
            [$rows['soc2_categories']['field'], $rows['soc2_categories']['old'], $rows['soc2_categories']['new']]);
        $this->assertArrayNotHasKey('cyber_essentials', $rows, 'unchanged frameworks are not logged');

        $this->assertSame(['iso27001' => 'ISO/IEC 27001:2022', 'cyber_essentials' => 'Cyber Essentials', 'soc2' => 'SOC 2'], ComplianceForm::frameworkOptions());
        $this->assertNotEmpty((new ComplianceForm())->attributeHints()['soc2_categories']);
    }
}
