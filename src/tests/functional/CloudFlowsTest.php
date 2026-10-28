<?php

namespace tests\functional;

use app\models\Attachment;
use app\models\AuditLog;
use app\modules\cloud\components\CloudExport;
use app\modules\cloud\components\CloudImporter;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use tests\FunctionalTestCase;
use Yii;
use yii\db\Query;
use yii\helpers\FileHelper;

/**
 * Cloud Inventory through the real controllers against the demo data: accounts
 * and assets, cloud checks in the Control Register, links from risks /
 * incidents / decisions, search, dashboard, threat intel and Cyber Essentials.
 */
class CloudFlowsTest extends FunctionalTestCase
{
    protected static bool $seed = true;

    private function id(string $table, string $column, string $value): int
    {
        $id = (new Query())->select('id')->from($table)->where([$column => $value])->scalar();
        $this->assertNotFalse($id, "$table $value missing from the demo data");
        return (int) $id;
    }

    private function asset(string $name): CloudAsset
    {
        return CloudAsset::findOne(['name' => $name]);
    }

    private function control(string $title): Control
    {
        return Control::find()->where(['like', 'title', $title])->one();
    }

    private function lastId(string $table): int
    {
        return (int) (new Query())->from($table)->max('id');
    }

    public function testDemoDataPages(): void
    {
        $this->loginAs('viewer');
        $this->get('/cloud/assets');
        $this->assertOk();
        $this->assertSee('customer-exports');
        $this->assertSee('Internet-facing');
        $this->assertNotSee('Add asset');

        $this->get('/cloud/assets?filter=non-compliant');
        $this->assertSee('legacy-reports');
        $this->assertSee('portal-web-02');
        $this->assertNotSee('erp-db');
        $this->get('/cloud/assets?filter=unsupported-os');
        $this->assertSee('dev-sandbox-01');
        $this->assertNotSee('portal-web-01');
        $this->get('/cloud/assets?filter=stale');
        $this->assertSee('legacy-reports');
        $this->assertSee('gw-text-warning');
        $this->get('/cloud/assets?filter=internet-facing&category=storage');
        $this->assertSee('legacy-reports');
        $this->assertNotSee('customer-portal-alb');
        $this->assertSee('Category: <strong>Storage</strong>');
        $this->get('/cloud/assets?filter=all&q=111122223333&sort=-criticality');
        $this->assertSee('erp-db');
        $this->get('/cloud/assets?category=nope');
        $this->assertSee('No records match the current filters.');

        $legacy = $this->asset('legacy-reports');
        $this->get("/cloud/assets/{$legacy->id}");
        $this->assertOk();
        $this->assertSee('Not encrypted at rest');
        $this->assertSee('Amazon Web Services');
        $this->assertSee('data-value="stale"');
        $this->assertSee('Sync');
        $this->assertSee('IaaS');
        $this->assertSee('Misconfigured cloud storage');

        $vm = $this->asset('portal-web-02');
        $this->get("/cloud/assets/{$vm->id}");
        $this->assertSee('Unsupported OS');
        $this->assertSee('Linux, IaaS');

        $acc = $this->id('cloud_account', 'reference', 'ACC-0002');
        $this->get('/cloud/accounts');
        $this->assertOk();
        $this->assertSee('Google Workspace');
        $this->get('/cloud/accounts?filter=non-compliant');
        $this->assertSee('Development (AWS)');
        $this->assertNotSee('Production (AWS)');
        $this->get('/cloud/accounts?filter=not-assessed');
        $this->assertSee('GitHub organisation');
        $this->get("/cloud/accounts/$acc");
        $this->assertOk();
        $this->assertSee('MFA on cloud root and admin accounts');
        $this->assertSee('dev-sandbox-01');
        $this->assertNotSee('Add asset');
        $workspace = $this->id('cloud_account', 'reference', 'ACC-0004');
        $this->get("/cloud/accounts/$workspace");
        $this->assertSee('SaaS, Office Suite, Identity Provider');
    }

    public function testAssetLifecycle(): void
    {
        $this->loginAs('analyst');
        $acc = $this->id('cloud_account', 'reference', 'ACC-0001');
        $guardrails = $this->control('Cloud storage guardrails');
        $this->get("/cloud/assets/create?account=$acc");
        $this->assertOk();
        $this->assertSee('Object storage bucket');
        $this->assertSee('data-gw-cloud-types="bucket"');
        $this->assertSee('value="production" selected', 'the account\'s environment');

        $base = ['account_id' => $acc, 'name' => 'fn-bucket', 'resource_type' => 'bucket', 'status' => 'active', 'criticality' => 'high',
            'data_classification' => 'confidential', 'encryption_at_rest' => 'yes', 'environment' => ''];
        $this->post('/cloud/assets/create', ['CloudAsset' => $base + ['public_ips' => '203.0.113.999', 'public_endpoints' => 'nope!']]);
        $this->assertOk();
        $this->assertSee('Invalid IP address');
        $this->assertSee('Not a hostname or http(s) URL');

        $this->post('/cloud/assets/create', ['CloudAsset' => $base + ['resource_id' => 'arn:aws:s3:::fn-bucket', 'internet_facing' => 1,
            'public_endpoints' => 'fn-bucket.s3.amazonaws.com', 'checks' => [$guardrails->id => 'pass']]]);
        $this->assertRedirect('/cloud/assets/');
        $asset = CloudAsset::findOne($this->lastId('cloud_asset'));
        $this->assertSame('production', $asset->environment, 'from the account');
        $this->assertSame('pass', $asset->getCheckResults()[$guardrails->id]);
        $this->assertStringContainsString("Cloud asset {$asset->reference} created.", $this->flash('success'));

        $this->post('/cloud/assets/create', ['CloudAsset' => $base + ['resource_id' => 'arn:aws:s3:::fn-bucket']]);
        $this->assertSee('Another asset in this account already has this resource ID');

        $this->post("/cloud/assets/{$asset->id}/update", ['CloudAsset' => $base + ['checks' => [$guardrails->id => 'fail']]]);
        $this->assertRedirect("/cloud/assets/{$asset->id}");
        $this->get("/cloud/assets/{$asset->id}");
        $this->assertSee('Non-compliant');
        $this->assertSee('gw-diff');
        $this->get("/cloud/assets/{$asset->id}/update");
        $this->assertOk();

        $this->post("/cloud/assets/{$asset->id}/archive");
        $this->assertRedirect('/cloud/assets');
        $this->get('/cloud/assets?filter=archived');
        $this->assertSee('fn-bucket');
        $this->post("/cloud/assets/{$asset->id}/restore");
        $this->assertRedirect("/cloud/assets/{$asset->id}");
        $this->post("/cloud/assets/{$asset->id}/archive");
        $this->loginAs('admin');
        $this->post("/cloud/assets/{$asset->id}/delete");
        $this->assertRedirect('filter=archived');
        $this->assertNull(CloudAsset::findOne($asset->id));
        $actions = AuditLog::find()->select('action')->where(['entity_type' => 'cloud_asset', 'entity_id' => $asset->id])->orderBy('id')->column();
        $this->assertSame(['create', 'update', 'archive', 'restore', 'archive', 'delete'], $actions);
    }

    public function testAccountLifecycle(): void
    {
        $this->loginAs('analyst');
        $mfa = $this->control('MFA on cloud root');
        $this->get('/cloud/accounts/create');
        $this->assertOk();
        $this->assertSee('AWS account ID');
        $this->post('/cloud/accounts/create', ['CloudAccount' => ['name' => 'Dupe', 'provider' => 'aws', 'account_identifier' => '111122223333',
            'environment' => 'production', 'status' => 'active', 'criticality' => 'low', 'data_classification' => 'internal']]);
        $this->assertSee('Another account of this provider already has this identifier');

        $this->post('/cloud/accounts/create', ['CloudAccount' => ['name' => 'Fn Azure', 'provider' => 'azure', 'account_identifier' => '00000000-0000-0000-0000-000000000001',
            'environment' => 'staging', 'status' => 'active', 'criticality' => 'medium', 'data_classification' => 'internal', 'owner_id' => '',
            'checks' => [$mfa->id => 'fail']]]);
        $this->assertRedirect('/cloud/accounts/');
        $account = CloudAccount::findOne($this->lastId('cloud_account'));
        $this->assertSame('ACC-0006', $account->reference);
        $this->get("/cloud/accounts/{$account->id}");
        $this->assertSee('Non-compliant');
        $this->assertSee('No assets recorded yet.');
        $this->assertSee('Add asset');
        $this->get("/cloud/accounts/{$account->id}/update");
        $this->assertOk();

        $this->post('/cloud/assets/create', ['CloudAsset' => ['account_id' => $account->id, 'name' => 'fn-vm', 'resource_type' => 'instance',
            'os' => 'Windows Server', 'os_version' => '2012 R2', 'status' => 'active', 'criticality' => 'low', 'data_classification' => 'internal']]);
        $asset = CloudAsset::findOne($this->lastId('cloud_asset'));
        $this->assertSame('staging', $asset->environment);

        // An account with assets is archived, not deleted
        $this->post("/cloud/accounts/{$account->id}/archive");
        $this->loginAs('admin');
        $this->post("/cloud/accounts/{$account->id}/delete");
        $this->assertRedirect("/cloud/accounts/{$account->id}");
        $this->assertStringContainsString('still has 1 asset(s)', $this->flash('warning'));
        $asset->delete();
        $this->post("/cloud/accounts/{$account->id}/delete");
        $this->assertRedirect('filter=archived');
        $this->assertNull(CloudAccount::findOne($account->id));
    }

    public function testViewerCannotWrite(): void
    {
        $this->loginAs('viewer');
        $asset = $this->asset('erp-db');
        $acc = $this->id('cloud_account', 'reference', 'ACC-0001');
        foreach (['/cloud/assets/create' => ['CloudAsset' => ['name' => 'nope']], "/cloud/assets/{$asset->id}/archive" => [],
                     '/cloud/accounts/create' => ['CloudAccount' => ['name' => 'nope']], "/cloud/accounts/$acc/archive" => [],
                     "/attachments/upload?entity=cloud_asset&id={$asset->id}" => []] as $url => $data) {
            $this->post($url, $data);
            $this->assertStatus(403, $url);
        }
        $this->loginAs('analyst');
        $this->post("/cloud/accounts/$acc/delete");
        $this->assertStatus(403);
    }

    public function testCloudChecksInTheControlRegister(): void
    {
        $this->loginAs('analyst');
        $this->get('/controls/create');
        $this->assertSee('control-cloudscope-account');
        $this->post('/controls/create', ['Control' => ['title' => 'Bad cloud check', 'status' => 'implemented', 'control_type' => 'preventive',
            'nature' => 'technical', 'cloud_check' => 'encryption', 'cloudScopeList' => ['account']]]);
        $this->assertOk();
        $this->assertSee('Only a check recorded on each asset / account (pass / fail) can apply to cloud accounts.');

        $this->post('/controls/create', ['Control' => ['title' => 'Fn: logging on every account', 'status' => 'implemented', 'control_type' => 'detective',
            'nature' => 'technical', 'cloud_check' => 'manual', 'cloudScopeList' => ['account', 'bucket'], 'riskIds' => '', 'decisionIds' => '']]);
        $this->assertRedirect('/controls/');
        $control = Control::findOne($this->lastId('control'));
        $this->assertSame('account,bucket', $control->cloud_scope);
        $this->get("/controls/{$control->id}");
        $this->assertSee('tab-cloud');
        $this->assertSee('Cloud accounts, Object storage bucket');
        $this->assertSee('in-service asset');
        $this->assertSee('cloud account');
        $this->get('/controls?filter=cloud-checks');
        $this->assertSee('Fn: logging on every account');
        $this->assertSee('data-value="cloud-check"');
        $this->get("/cloud/accounts?filter=active&control={$control->id}&result=unknown");
        $this->assertSee('Production (AWS)');
        $this->get("/cloud/assets?filter=active&control={$control->id}&result=unknown");
        $this->assertSee('dev-artifacts');
        $this->assertSee('Cloud check: <strong>');
        $this->get('/cloud/assets?filter=active&control=999999');
        $this->assertSee('not in force');
        $this->get('/cloud/accounts?filter=active&control=999999');
        $this->assertSee('not in force');

        foreach (['Encryption at rest', 'Supported operating systems'] as $title) {
            $this->get('/controls/' . $this->control($title)->id);
            $this->assertSee('tab-cloud');
        }

        // Not in force: still described, with a notice
        $control->status = Control::STATUS_PLANNED;
        $this->assertTrue($control->save());
        $this->get("/controls/{$control->id}");
        $this->assertSee('This check is not in force');
    }

    public function testLinksFromRisksIncidentsAndDecisions(): void
    {
        $this->loginAs('analyst');
        $bucket = $this->asset('customer-exports');
        $risk = Risk::findOne(['reference' => 'RSK-0001']);
        $this->get("/risks/{$risk->id}/update");
        $this->assertSee('risk-cloudassetids');
        $risk->populateLinks();
        $risk->cloudAssetIds = [$bucket->id];
        $this->assertTrue($risk->save(), json_encode($risk->getErrors()));
        $this->get("/risks/{$risk->id}");
        $this->assertSee('Affected cloud assets');
        $this->assertSee($bucket->reference);
        $this->get("/risks/{$risk->id}/pdf");
        $this->assertOk();

        $incident = Incident::find()->where(['like', 'title', 'Publicly accessible S3'])->one();
        $this->get("/incidents/{$incident->id}");
        $this->assertSee($bucket->reference);
        $this->get("/incidents/{$incident->id}/update");
        $this->assertSee('Filter cloud assets');

        $decision = Decision::find()->where(['like', 'title', 'Cloudflare for DDoS'])->one();
        $this->get("/decisions/{$decision->id}");
        $this->assertSee('Related cloud assets');
        $this->assertSee('Portal WAF ruleset');
        $this->get("/decisions/{$decision->id}/pdf");
        $this->assertOk();
        $this->get("/decisions/{$decision->id}/update");
        $this->assertSee('Filter cloud assets');

        $this->get("/cloud/assets/{$bucket->id}");
        $this->assertSee($risk->reference);
        $this->assertSee($incident->reference);
        $this->assertSee('T1530', 'technique observed on the linked incident');
    }

    public function testSearchDashboardIntelAndCyberEssentials(): void
    {
        $this->loginAs('viewer');
        $alb = $this->asset('customer-portal-alb');
        foreach (['CLD-0001' => '/cloud/assets/', 'ACC-0001' => '/cloud/accounts/'] as $ref => $target) {
            $this->get('/search?q=' . $ref);
            $this->assertRedirect($target);
        }
        $this->get('/search?q=arn:aws:s3');
        $this->assertSee('gw-search-group--cloud-assets');
        $this->assertSee('customer-exports');
        $this->get('/search?q=example-org');
        $this->assertSee('GitHub organisation');

        $this->get('/');
        $this->assertOk();
        foreach (['Cloud assets', 'Cloud compliance', 'gw-dashboard-panel--cloud-posture', 'gw-attention__group--cloud-exposed',
                     'gw-attention__group--cloud-non-compliant', 'gw-attention__group--cloud-accounts', 'gw-attention__group--cloud-unsupported-os',
                     'gw-attention__group--stale-cloud'] as $text) {
            $this->assertSee($text);
        }

        $this->get('/intel/ip/203.0.113.10');
        $this->assertOk();
        $this->assertSee('Your cloud asset · ' . $alb->reference);
        $this->assertSee('Your cloud assets');

        $this->get('/frameworks/cyber-essentials');
        $this->assertSee('Cloud checks in scope');
        $this->assertSee('MFA on cloud root and admin accounts');
    }

    public function testAttachmentsOnCloudRecords(): void
    {
        $this->loginAs('analyst');
        $asset = $this->asset('erp-db');
        $tmp = tempnam(sys_get_temp_dir(), 'gwfn');
        file_put_contents($tmp, "RDS encryption settings\n");
        try {
            $this->post("/attachments/upload?entity=cloud_asset&id={$asset->id}", ['description' => 'Config export'],
                ['files' => ['name' => ['rds.txt'], 'type' => ['text/plain'], 'tmp_name' => [$tmp], 'error' => [UPLOAD_ERR_OK], 'size' => [24]]]);
            $this->assertRedirect("/cloud/assets/{$asset->id}");
            $this->assertNotNull(Attachment::findOne(['entity_type' => 'cloud_asset', 'entity_id' => $asset->id]));
            $this->get("/cloud/assets/{$asset->id}");
            $this->assertSee('Files (1)');
            $this->assertSee('rds.txt');
        } finally {
            @unlink($tmp);
            FileHelper::removeDirectory(Yii::$app->params['attachments.path']);
        }
    }

    public function testImportedAssetsShowTheirSync(): void
    {
        // An Azure export: a new account, assets with long lower-cased resource IDs and OS details
        $records = CloudExport::for('azure')->parse(file_get_contents(Yii::getAlias('@app/tests/_data/cloud/azure-resource-graph.json')));
        $importer = new CloudImporter(date('Y-m-d H:i:s'));
        $results = $importer->import($records, 'Imported in a functional test.');
        $vm = $results[0]['asset'];
        $this->assertSame(CloudImporter::CREATED, $results[0]['action']);

        $this->loginAs('viewer');
        $this->get("/cloud/assets/{$vm->id}");
        $this->assertOk();
        $this->assertSee('Synced from');
        $this->assertSee('Microsoft Azure');
        $this->assertSee('/subscriptions/00000000-0000-0000-0000-000000000001/resourcegroups/rg-prod/providers/microsoft.compute/virtualmachines/vm-web-01');
        $this->assertSee('Ubuntu 22.04');

        $account = $importer->createdAccounts[0];
        $this->get("/cloud/accounts/{$account->id}");
        $this->assertOk();
        $this->assertSee('Azure subscription 00000000-0000-0000-0000-000000000001');
        $this->assertSee('Created by the import');
        $this->get('/cloud/assets?filter=all&provider=azure');
        $this->assertSee('fn-orders');
    }
}
