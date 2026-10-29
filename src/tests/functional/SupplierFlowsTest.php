<?php

namespace tests\functional;

use app\models\AuditLog;
use app\models\RecordReview;
use app\modules\cloud\models\CloudAccount;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use app\modules\supplier\components\SupplierChecks;
use app\modules\supplier\models\Supplier;
use app\modules\supplier\models\SupplierAssurance;
use tests\FunctionalTestCase;
use yii\db\Query;

/**
 * Supplier Register through the real controllers against the demo data:
 * register filters, the supplier page and PDF, assurance, reviews, supplier
 * checks in the Control Register, cloud accounts and links from risks,
 * incidents, decisions and controls, search and the dashboard.
 */
class SupplierFlowsTest extends FunctionalTestCase
{
    protected static bool $seed = true;

    private function supplier(string $name): Supplier
    {
        $s = Supplier::findOne(['name' => $name]);
        $this->assertNotNull($s, "$name missing from the demo data");
        return $s;
    }

    private function lastId(string $table): int
    {
        return (int) (new Query())->from($table)->max('id');
    }

    public function testRegisterFilters(): void
    {
        $this->loginAs('viewer');
        $this->get('/suppliers');
        $this->assertOk();
        $this->assertSee('Amazon Web Services');
        $this->assertSee('Tailspin Security Operations');
        $this->assertSee('data-kind="tier"');
        $this->assertNotSee('Add supplier');

        $cases = [
            'non-compliant' => [['GitHub', 'Contoso Payroll Services', 'Northwind Registrars'], ['Amazon Web Services', 'Tailspin']],
            'onboarding' => [['Tailspin Security Operations'], ['Fabrikam']],
            'tier-1' => [['Fabrikam Managed IT', 'Tailspin Security Operations'], ['Adventure Works', 'Cloudflare']],
            'review-overdue' => [['Fabrikam Managed IT'], ['Cloudflare']],
            'assurance' => [['Amazon Web Services', 'GitHub', 'Northwind Registrars'], ['Fabrikam', 'Cloudflare']],
            'renewals' => [['GitHub', 'Fabrikam Managed IT'], ['Cloudflare']],
            'active' => [['Fabrikam Managed IT'], ['Tailspin']],
        ];
        foreach ($cases as $filter => [$see, $notSee]) {
            $this->get('/suppliers?filter=' . $filter);
            $this->assertOk();
            foreach ($see as $text) {
                $this->assertSee($text);
            }
            foreach ($notSee as $text) {
                $this->assertNotSee($text);
            }
        }
        $this->get('/suppliers?filter=not-assessed');
        $this->assertSee('No records match the current filters.');
        $this->get('/suppliers?filter=mine');
        $this->assertSee('No records match the current filters.');
        $this->get('/suppliers?filter=archived');
        $this->assertOk();
        $this->get('/suppliers?tier=3');
        $this->assertSee('Adventure Works Print');
        $this->assertNotSee('Fabrikam');
        $this->get('/suppliers?q=payroll&sort=-criticality');
        $this->assertSee('Contoso Payroll Services');
        $this->assertNotSee('GitHub');
        $this->get('/suppliers?name=GitHub+B.V.&status=active&access_level=none&criticality=medium');
        $this->assertSee('GitHub');
        $this->get('/suppliers?filter=nope');
        $this->assertSee('No records match the current filters.');

        // From a supplier check's page
        $diligence = Control::findOne(['title' => 'Supplier security due diligence']);
        $this->get("/suppliers?filter=active&control={$diligence->id}&result=unknown");
        $this->assertSee('Supplier check: <strong>' . $diligence->reference);
        $this->assertSee('Contoso Payroll Services');
        $this->assertNotSee('Fabrikam');
        $this->get('/suppliers?control=999999');
        $this->assertSee('not in force');
        $this->assertSee('No records match the current filters.');
    }

    public function testSupplierPageAndPdf(): void
    {
        $this->loginAs('viewer');
        $msp = $this->supplier('Fabrikam Managed IT');
        $this->get("/suppliers/{$msp->id}");
        $this->assertOk();
        foreach (['Tier 1 · Critical', 'High criticality, privileged access to our systems', 'Review overdue', 'Notice deadline passed',
                     'Privileged / admin access', 'Within 24 hours', 'Cyber Essentials Plus', 'Vendor remote access is not monitored',
                     'Supplier security due diligence', 'Reviewed every', 'Revoke all accounts'] as $text) {
            $this->assertSee($text);
        }
        $this->assertNotSee('Add assurance');
        $this->assertNotSee('Record a review');

        $aws = $this->supplier('Amazon Web Services');
        $this->get("/suppliers/{$aws->id}");
        foreach (['Production (AWS)', 'Development (AWS)', 'Assurance expiring', 'Publicly accessible S3', 'Cloud storage guardrails',
                     'Amazon Web Services EMEA SARL', 'Data processing agreement signed'] as $text) {
            $this->assertSee($text);
        }
        $print = $this->supplier('Adventure Works Print');
        $this->get("/suppliers/{$print->id}");
        $this->assertSee('Nothing raises it above Tier 3');
        $this->assertSee('Not needed: no personal data');
        $this->assertSee('No assurance recorded yet.');
        $contoso = $this->supplier('Contoso Payroll Services');
        $this->get("/suppliers/{$contoso->id}");
        $this->assertSee('No data processing agreement signed');
        $this->assertSee('Payroll provider suffers a data breach');

        foreach ([$msp, $aws, $contoso] as $s) {
            $this->get("/suppliers/{$s->id}/pdf");
            $this->assertOk();
            $this->assertStringStartsWith('%PDF-', $this->body);
            $this->assertStringContainsString("{$s->reference}-", (string) $this->response->headers->get('content-disposition'));
        }
        $this->assertTrue(AuditLog::find()->where(['entity_type' => 'supplier', 'entity_id' => $aws->id, 'action' => 'export'])->exists());
        $this->get('/suppliers/999999');
        $this->assertStatus(404);
    }

    public function testSupplierLifecycle(): void
    {
        $this->loginAs('analyst');
        $diligence = Control::findOne(['title' => 'Supplier security due diligence']);
        $this->get('/suppliers/create');
        $this->assertOk();
        $this->assertSee('Supplier security due diligence');
        $this->assertSee('DPA and assurance checks are read from');
        $this->assertSee('Managed IT / MSP');

        $this->post('/suppliers/create', ['Supplier' => ['name' => 'Fabrikam Managed IT', 'status' => 'active', 'criticality' => 'low',
            'data_classification' => 'public', 'access_level' => 'none', 'website' => 'nope nope', 'contract_start' => '2026-10-01', 'contract_end' => '2026-01-01']]);
        $this->assertOk();
        $this->assertSee('Another supplier already has this name.');
        $this->assertSee('Website is not a valid URL.');
        $this->assertSee('Renewal / end date cannot be before Contract start.');

        $form = ['name' => 'Wingtip Couriers', 'status' => 'active', 'criticality' => 'medium', 'data_classification' => 'confidential',
            'access_level' => 'none', 'personal_data' => '1', 'dpa_status' => '', 'website' => 'wingtip.example', 'contract_end' => date('Y-m-d', strtotime('+80 days')),
            'notice_days' => '60', 'checks' => [$diligence->id => 'fail']];
        $this->post('/suppliers/create', ['Supplier' => $form]);
        $this->assertRedirect('/suppliers/');
        $s = Supplier::findOne($this->lastId('supplier'));
        $this->assertSame('SUP-0010', $s->reference);
        $this->assertStringContainsString("Supplier {$s->reference} created.", $this->flash('success'));
        $this->assertSame(2, (int) $s->tier);
        $this->assertSame('fail', $s->getCheckResults()[$diligence->id]);
        $this->get("/suppliers/{$s->id}");
        $this->assertSee('Non-compliant');
        $this->assertSee('Notice period closing');
        $this->assertSee('Add assurance');
        $this->get("/suppliers/{$s->id}/update");
        $this->assertOk();
        $this->post("/suppliers/{$s->id}/update", ['Supplier' => ['dpa_status' => 'signed', 'checks' => [$diligence->id => 'pass']] + $form]);
        $this->assertRedirect("/suppliers/{$s->id}");
        $this->get("/suppliers/{$s->id}");
        $this->assertSee('gw-diff');

        // Assurance: refused, added, removed
        $this->post("/suppliers/{$s->id}/add-assurance", ['SupplierAssurance' => ['type' => '', 'issued_on' => '2026-10-01', 'expires_on' => '2026-01-01']]);
        $this->assertOk();
        $this->assertSee('Type cannot be blank.');
        $this->assertSee('The expiry date must be after the issue date.');
        $this->post("/suppliers/{$s->id}/add-assurance", ['SupplierAssurance' => ['type' => 'cyber_essentials', 'description' => 'Whole company',
            'issued_on' => date('Y-m-d', strtotime('-30 days')), 'expires_on' => date('Y-m-d', strtotime('+335 days')), 'evidence' => 'SharePoint › Suppliers']]);
        $this->assertRedirect('#tab-assurance');
        $this->assertSame('Cyber Essentials added to ' . $s->reference . '.', $this->flash('success'));
        $assurance = SupplierAssurance::findOne(['supplier_id' => $s->id]);
        $this->get("/suppliers/{$s->id}");
        $this->assertSee('Whole company');
        $this->assertSee('SharePoint › Suppliers');
        $this->post("/suppliers/{$s->id}/delete-assurance?assurance=999999");
        $this->assertStatus(404);
        $this->post("/suppliers/{$s->id}/delete-assurance?assurance={$assurance->id}");
        $this->assertRedirect('#tab-assurance');
        $this->assertNull(SupplierAssurance::findOne($assurance->id));

        // A review moves the review date on
        $next = date('Y-m-d', strtotime('+24 months'));
        $this->post("/reviews/record?entity=supplier&id={$s->id}", ['RecordReview' => ['reviewed_on' => date('Y-m-d'), 'outcome' => 'Onboarding review done.',
            'next_review_date' => $next]]);
        $this->assertRedirect();
        $this->assertSame($next, Supplier::findOne($s->id)->next_review_date);
        $this->assertSame(1, count(RecordReview::listFor($s)));

        // Archived: read-only for assurance
        $kept = new SupplierAssurance(['supplier_id' => $s->id, 'type' => 'iso27001']);
        $this->assertTrue($kept->save());
        $this->post("/suppliers/{$s->id}/archive");
        $this->assertRedirect('/suppliers');
        $this->post("/suppliers/{$s->id}/add-assurance", ['SupplierAssurance' => ['type' => 'iso27001']]);
        $this->assertRedirect("/suppliers/{$s->id}");
        $this->assertStringContainsString('Restore the supplier', $this->flash('warning'));
        $this->post("/suppliers/{$s->id}/delete-assurance?assurance={$kept->id}");
        $this->assertRedirect("/suppliers/{$s->id}");
        $this->assertNotNull(SupplierAssurance::findOne($kept->id));
        $this->post("/suppliers/{$s->id}/restore");
        $this->assertRedirect("/suppliers/{$s->id}");

        // A supplier with cloud accounts is archived, not deleted
        $account = CloudAccount::findOne(['name' => 'Cloudflare']);
        $this->post("/cloud/accounts/{$account->id}/update", ['CloudAccount' => ['supplier_id' => $s->id] + $account->getAttributes(['name', 'provider',
            'account_identifier', 'environment', 'status', 'criticality', 'data_classification', 'owner_id'])]);
        $this->assertRedirect("/cloud/accounts/{$account->id}");
        $this->get("/cloud/accounts/{$account->id}");
        $this->assertSee('Wingtip Couriers');
        $this->post("/suppliers/{$s->id}/archive");
        $this->loginAs('admin');
        $this->post("/suppliers/{$s->id}/delete");
        $this->assertRedirect("/suppliers/{$s->id}");
        $this->assertStringContainsString('still has 1 cloud account(s)', $this->flash('warning'));
        $account->refresh();
        $account->updateAttributes(['supplier_id' => $this->supplier('Cloudflare')->id]);
        $this->post("/suppliers/{$s->id}/delete");
        $this->assertRedirect('filter=archived');
        $this->assertNull(Supplier::findOne($s->id));
        $actions = AuditLog::find()->select('action')->where(['entity_type' => 'supplier', 'entity_id' => $s->id])->orderBy('id')->column();
        $this->assertSame(['create', 'update', 'assurance', 'assurance_remove', 'review', 'assurance', 'archive', 'restore', 'archive', 'delete'], $actions);
    }

    public function testLinksChecksSearchAndDashboard(): void
    {
        $this->loginAs('analyst');
        $aws = $this->supplier('Amazon Web Services');
        $cloudflare = $this->supplier('Cloudflare');

        // Link fields on the other records' forms, and the links on their pages
        foreach (['/risks/' . Risk::find()->one()->id, '/incidents/' . Incident::find()->one()->id, '/decisions/' . Decision::find()->one()->id,
                     '/controls/' . Control::find()->one()->id] as $url) {
            $this->get("$url/update");
            $this->assertSee('Filter suppliers');
        }
        $risk = Risk::find()->where(['like', 'title', 'Misconfigured cloud storage'])->one();
        $this->get("/risks/{$risk->id}");
        $this->assertSee('Related suppliers');
        $this->assertSee($aws->reference);
        $this->get("/risks/{$risk->id}/pdf");
        $this->assertOk();
        $incident = Incident::find()->where(['like', 'title', 'Publicly accessible S3'])->one();
        $this->get("/incidents/{$incident->id}");
        $this->assertSee('Suppliers involved');
        $this->get("/incidents/{$incident->id}/pdf");
        $this->assertOk();
        $decision = Decision::find()->where(['like', 'title', 'Cloudflare for DDoS'])->one();
        $this->get("/decisions/{$decision->id}");
        $this->assertSee($cloudflare->reference);
        $this->get("/decisions/{$decision->id}/pdf");
        $this->assertOk();
        $guardrails = Control::find()->where(['like', 'title', 'Cloud storage guardrails'])->one();
        $this->get("/controls/{$guardrails->id}");
        $this->assertSee('Suppliers it relies on');
        $this->get("/controls/{$guardrails->id}/pdf");
        $this->assertOk();
        $this->get('/cloud/accounts/' . CloudAccount::findOne(['name' => 'Production (AWS)'])->id);
        $this->assertSee('Amazon Web Services');
        $this->assertSee('data-kind="tier"');

        // Supplier checks in the Control Register
        $assurance = Control::findOne(['title' => 'Current security assurance from critical suppliers']);
        $this->get("/controls/{$assurance->id}");
        $this->assertSee('Checked on suppliers');
        $this->assertSee('Tier 1, Tier 2 suppliers');
        $this->assertSee('unexpired certificate or report');
        $this->get("/controls/{$assurance->id}/pdf");
        $this->assertOk();
        $dpa = Control::findOne(['title' => 'Data processing agreements with processors']);
        $this->get("/controls/{$dpa->id}");
        $this->assertSee('All suppliers processing personal data');
        $this->get('/controls?filter=supplier-checks');
        $this->assertSee('Supplier security due diligence');
        $this->assertSee('data-value="supplier-check"');
        $this->assertNotSee('Cloud storage guardrails');

        $this->post('/controls/create', ['Control' => ['title' => 'Supplier MFA on admin access', 'status' => 'planned', 'control_type' => 'preventive',
            'nature' => 'technical', 'supplier_check' => 'manual', 'supplierTierList' => ['1'], 'supplierIds' => [$aws->id]]]);
        $this->assertRedirect('/controls/');
        $control = Control::findOne($this->lastId('control'));
        $this->assertSame('1', $control->supplier_tiers);
        $this->get("/controls/{$control->id}");
        $this->assertSee('This check is not in force');
        $this->assertSee($aws->reference);

        // Search and dashboard
        $this->get('/search?q=' . $aws->reference);
        $this->assertRedirect("/suppliers/{$aws->id}");
        $this->get('/search?q=Fabrikam');
        $this->assertSee('gw-search-group--suppliers');
        $this->assertSee('data-kind="tier"');
        $this->get('/');
        $this->assertOk();
        foreach (['gw-attention__group--supplier-checks', 'gw-attention__group--supplier-review', 'gw-attention__group--supplier-assurance',
                     'gw-attention__group--supplier-renewals', 'Suppliers'] as $text) {
            $this->assertSee($text);
        }
        $this->get('/admin/lookup?type=supplier_category');
        $this->assertStatus(403);
        $this->loginAs('admin');
        $this->get('/admin/lookup?type=supplier_category');
        $this->assertSee('Supplier categories');
    }

    public function testWithoutSupplierChecks(): void
    {
        $this->loginAs('analyst');
        $ids = Control::find()->supplierChecks()->select('id')->column();
        Control::updateAll(['status' => Control::STATUS_PLANNED], ['id' => $ids]);
        SupplierChecks::reset();
        try {
            $this->get('/suppliers/create');
            $this->assertSee('No supplier check recorded per supplier is in force.');
            $this->assertNotSee('DPA and assurance checks are read from');
            $s = $this->supplier('Adventure Works Print');
            $this->get("/suppliers/{$s->id}");
            $this->assertSee('No supplier check in force applies to this supplier.');
            $this->get("/suppliers/{$s->id}/pdf");
            $this->assertOk();
        } finally {
            Control::updateAll(['status' => Control::STATUS_IMPLEMENTED], ['id' => $ids]);
            SupplierChecks::reset();
        }
    }

    public function testViewerCannotWrite(): void
    {
        $this->loginAs('viewer');
        $s = $this->supplier('GitHub');
        foreach (['/suppliers/create' => ['Supplier' => ['name' => 'nope']], "/suppliers/{$s->id}/update" => ['Supplier' => ['name' => 'nope']],
                     "/suppliers/{$s->id}/archive" => [], "/suppliers/{$s->id}/add-assurance" => ['SupplierAssurance' => ['type' => 'iso27001']],
                     "/suppliers/{$s->id}/delete-assurance?assurance=1" => [], "/reviews/record?entity=supplier&id={$s->id}" => ['RecordReview' => ['outcome' => 'x']],
                     "/attachments/upload?entity=supplier&id={$s->id}" => []] as $url => $data) {
            $this->post($url, $data);
            $this->assertStatus(403, $url);
        }
        $this->loginAs('analyst');
        $this->post("/suppliers/{$s->id}/delete");
        $this->assertStatus(403);
        $this->get("/suppliers/{$s->id}/add-assurance");
        $this->assertStatus(405);
    }
}
