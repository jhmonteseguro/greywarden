<?php

namespace tests\functional;

use app\models\Setting;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\models\Control;
use app\modules\control\models\FrameworkRequirement;
use tests\FunctionalTestCase;

/**
 * Admin › Settings › Compliance frameworks over HTTP: switching frameworks
 * off and on, the SOC 2 categories in scope, and where that shows (sidebar,
 * framework pages, dashboard, search, control forms and views).
 */
class FrameworkSettingsTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::deleteAll();
        Setting::reset();
    }

    private function save(array $frameworks, array $categories = []): void
    {
        $this->post('/admin/settings', ['ComplianceForm' => ['frameworks' => $frameworks ?: '', 'soc2_categories' => $categories ?: '']]);
    }

    private function requirementId(string $externalId): int
    {
        return (int) FrameworkRequirement::find()->select('id')->where(['external_id' => $externalId])->scalar();
    }

    private function control(array $externalIds): Control
    {
        $control = new Control(['title' => 'Scoped MFA control', 'status' => Control::STATUS_IMPLEMENTED, 'implemented_on' => date('Y-m-d'),
            'control_type' => 'preventive', 'nature' => 'technical', 'requirementIds' => array_map([$this, 'requirementId'], $externalIds)]);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        return $control;
    }

    public function testEverythingIsOnByDefault(): void
    {
        $this->loginAs('admin');
        $this->get('/admin/settings');
        $this->assertOk();
        $this->assertSee('Compliance frameworks');
        $this->assertSee('id="complianceform-frameworks-soc2"');
        $this->assertSee('form-check form-switch');
        $this->assertSee('id="complianceform-soc2_categories-privacy"');
        $this->assertNotSee('soc2_categories-security');   // Security is not optional

        foreach (['iso27001', 'cyber-essentials', 'soc2', 'nist-csf'] as $slug) {
            $this->assertSee('href="/frameworks/' . $slug . '"');
        }
        $this->get('/frameworks/soc2');
        $this->assertOk();
        $this->assertSee('SOC 2 readiness');
        $this->assertSee('CC6.1');
        $this->assertNotSee('A1.2');
        $this->assertSee('Trust Services categories in scope:');
        $this->assertSee('href="/admin/settings#compliance"');

        $this->loginAs('viewer');
        $this->get('/frameworks/soc2');
        $this->assertSee('An administrator can change them in Settings.');
        $this->get('/frameworks/requirement/' . $this->requirementId('CC6.1'));
        $this->assertOk();
        $this->assertSee('AICPA 2017 Trust Services Criteria');
        $this->assertSee('Applicability');
        $this->get('/frameworks/requirement/' . $this->requirementId('A1.2'));
        $this->assertStatus(404);   // optional categories are out of scope until chosen
    }

    public function testSwitchingFrameworksOffHidesThemEverywhere(): void
    {
        $control = $this->control(['A.8.5', 'CE-UA-4', 'CC6.1']);
        $this->loginAs('admin');
        $this->save(['iso27001', 'soc2'], ['availability']);
        $this->assertRedirect('/admin/settings#compliance');
        $this->assertSame('Compliance frameworks saved.', $this->flash('success'));
        $this->assertSame('0', Setting::get('framework.cyber_essentials'));
        $this->assertSame('availability', Setting::get(FrameworkCatalogue::SETTING_SOC2_CATEGORIES));

        $this->loginAs('viewer');
        $this->get('/');
        $this->assertOk();
        $this->assertNotSee('href="/frameworks/cyber-essentials"');
        $this->assertSee('href="/frameworks/soc2"');
        $this->assertMatchesRegularExpression('/class="col-lg-4">\s*<h3 class="gw-controls-panel__heading">Implemented/', $this->body);   // plus two frameworks

        $this->get('/frameworks/cyber-essentials');
        $this->assertStatus(404);
        $this->get('/frameworks/requirement/' . $this->requirementId('CE-UA-4'));
        $this->assertStatus(404);
        $this->get('/frameworks');
        $this->assertSee('SOC 2');
        $this->assertNotSee('Cyber Essentials');

        $this->get('/frameworks/soc2');
        $this->assertSee('A1.2');   // Availability added to the scope
        $this->assertNotSee('P4.3');
        $this->assertSee($control->reference);

        $this->get('/search?q=CE-UA-4');
        $this->assertOk();   // no jump to a requirement that is switched off
        $this->assertNotSee('/frameworks/requirement/' . $this->requirementId('CE-UA-4'));
        $this->get('/search?q=CC6.1');
        $this->assertRedirect('/frameworks/requirement/' . $this->requirementId('CC6.1'));
        $this->get('/search?q=Logical');
        $this->assertSee('ISO 27001 / SOC 2');

        $this->get("/controls/{$control->id}");
        $this->assertSee('href="/frameworks/requirement/' . $this->requirementId('CC6.1') . '"');
        $this->assertNotSee('href="/frameworks/requirement/' . $this->requirementId('CE-UA-4') . '"');   // its History still names it
        $this->get("/controls/{$control->id}/pdf");
        $this->assertOk();
        $this->get('/controls?requirement=CE-UA');
        $this->assertNotSee($control->reference);

        // Editing the control keeps its hidden Cyber Essentials link
        $this->loginAs('analyst');
        $this->get("/controls/{$control->id}/update");
        $this->assertSee('id="control-requirementids-soc2"');
        $this->assertNotSee('id="control-requirementids-cyber-essentials"');
        $this->assertSee('The framework pages under Compliance are built from these mappings.');
        $this->post("/controls/{$control->id}/update", ['Control' => ['title' => 'Scoped MFA control', 'status' => 'implemented', 'control_type' => 'preventive',
            'nature' => 'technical', 'requirementIds' => [$this->requirementId('A.8.5')]]]);
        $this->assertRedirect("/controls/{$control->id}");
        $this->assertSame(['iso27001' => ['A.8.5']], Control::findOne($control->id)->frameworkSummary());

        $this->loginAs('admin');
        $this->save(['iso27001', 'cyber_essentials', 'soc2']);
        $this->assertSame(['iso27001' => ['A.8.5'], 'cyber_essentials' => ['CE-UA-4']], Control::findOne($control->id)->frameworkSummary());
        $this->get('/');
        $this->assertMatchesRegularExpression('/class="col-md-6 col-xxl-3">\s*<h3 class="gw-controls-panel__heading">Implemented/', $this->body);
        $this->get("/controls/{$control->id}");
        $this->assertSee('col-lg-6 col-xxl-3');
    }

    public function testNistCsfProfile(): void
    {
        $control = $this->control(['A.8.5', 'PR.AA-03']);
        $this->loginAs('viewer');
        $this->get('/');
        $this->assertSee('href="/frameworks/nist-csf"');
        // Implemented controls plus four frameworks: rows of three
        $this->assertMatchesRegularExpression('/class="col-md-6 col-xl-4">\s*<h3 class="gw-controls-panel__heading">Implemented/', $this->body);

        $this->get('/frameworks/nist-csf');
        $this->assertOk();
        $this->assertSee('NIST CSF 2.0 profile');
        $this->assertSee('NIST Cybersecurity Framework 2.0');
        $this->assertSee('GV.OC Organizational Context');
        $this->assertSee('Your current profile');
        $this->assertSee($control->reference);
        $this->get('/frameworks/nist-csf?state=met');
        $this->assertSee('PR.AA-03');
        $this->assertNotSee('PR.AA-01');

        $this->get('/frameworks/requirement/' . $this->requirementId('PR.AA-03'));
        $this->assertOk();
        $this->assertSee('NIST CSF 2.0 subcategory (outcome)');
        $this->get('/frameworks');
        $this->assertSee('Current profile against the CSF 2.0 outcomes');
        $this->get('/search?q=GV.SC-01');
        $this->assertRedirect('/frameworks/requirement/' . $this->requirementId('GV.SC-01'));

        $this->get("/controls/{$control->id}");
        $this->assertSee('href="/frameworks/requirement/' . $this->requirementId('PR.AA-03') . '"');
        $this->assertSee('col-md-6 col-xl-4');
        $this->get('/controls?requirement=PR.AA');
        $this->assertSee($control->reference);

        $this->loginAs('analyst');
        $this->get('/controls/create');
        $this->assertSee('id="control-requirementids-nist-csf"');
        $this->assertSee('NIST CSF 2.0 outcomes');

        $this->loginAs('admin');
        $this->save(['iso27001']);
        $this->get('/frameworks/nist-csf');
        $this->assertStatus(404);
        $this->get('/frameworks/requirement/' . $this->requirementId('PR.AA-03'));
        $this->assertStatus(404);
    }

    public function testEveryFrameworkOff(): void
    {
        $this->loginAs('admin');
        $this->save([]);
        $this->assertRedirect('/admin/settings#compliance');
        $this->assertSame([], FrameworkCatalogue::enabled());

        $this->get('/');
        $this->assertNotSee('<span>Compliance</span>');
        $this->assertNotSee('href="/frameworks"');
        $this->get('/frameworks');
        $this->assertSee('Every compliance framework is switched off.');
        $this->assertSee('Switch one on in Settings.');
        $this->get('/controls/create');
        $this->assertNotSee('>Frameworks</legend>');

        $this->loginAs('viewer');
        $this->get('/frameworks');
        $this->assertNotSee('Switch one on in Settings.');
        $this->get('/search?q=A.8.5');
        $this->assertOk();
        $this->assertNotSee('gw-search-group--requirements');
    }

    public function testInvalidChoicesAndPermissions(): void
    {
        $this->loginAs('admin');
        $this->save(['iso27001', 'pci_dss'], ['security']);
        $this->assertOk();
        $this->assertSee('Frameworks is invalid.');
        $this->assertSame(0, (int) Setting::find()->count());

        foreach (['analyst', 'viewer'] as $role) {
            $this->loginAs($role);
            $this->save(['iso27001']);
            $this->assertStatus(403);
        }
        $this->assertSame(['iso27001', 'cyber_essentials', 'soc2', 'nist_csf'], FrameworkCatalogue::enabled());

        // The branding form still saves on its own
        $this->loginAs('admin');
        $this->post('/admin/settings', ['BrandingForm' => ['app_name' => 'Acme', 'organisation' => '', 'tagline' => '', 'login_notice' => '', 'pdf_classification' => '']]);
        $this->assertRedirect('/admin/settings');
        $this->assertSame('Branding saved.', $this->flash('success'));
        $this->assertSame(['iso27001', 'cyber_essentials', 'soc2', 'nist_csf'], FrameworkCatalogue::enabled());
    }
}
