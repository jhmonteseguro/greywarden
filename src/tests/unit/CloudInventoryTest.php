<?php

namespace tests\unit;

use app\helpers\Badge;
use app\models\Attachment;
use app\models\AuditLog;
use app\modules\attack\components\CloudAttack;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudCheck;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\components\CloudPosture;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\LocalContext;
use app\modules\inventory\components\OsLifecycle;
use app\modules\risk\models\Risk;
use tests\DbTestCase;
use Yii;

class CloudInventoryTest extends DbTestCase
{
    private function makeAccount(array $attrs = []): CloudAccount
    {
        $account = new CloudAccount($attrs + [
            'name' => 'Production (AWS)',
            'provider' => 'aws',
            'account_identifier' => '111122223333',
            'environment' => 'production',
            'status' => CloudAccount::STATUS_ACTIVE,
            'criticality' => 'high',
            'data_classification' => 'confidential',
        ]);
        $this->assertTrue($account->save(), json_encode($account->getErrors()));
        return $account;
    }

    private function makeAsset(CloudAccount $account, array $attrs = []): CloudAsset
    {
        $asset = new CloudAsset($attrs + [
            'account_id' => $account->id,
            'name' => 'customer-exports',
            'resource_type' => 'bucket',
            'status' => CloudAsset::STATUS_ACTIVE,
            'criticality' => 'high',
            'data_classification' => 'confidential',
        ]);
        $this->assertTrue($asset->save(), json_encode($asset->getErrors()));
        return $asset;
    }

    /** A cloud-check control in force */
    private function makeCheck(array $attrs): Control
    {
        $control = new Control($attrs + [
            'title' => 'Test cloud check',
            'status' => Control::STATUS_IMPLEMENTED,
            'control_type' => 'preventive',
            'nature' => 'technical',
            'cloud_check' => Control::CHECK_MANUAL,
        ]);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
        return $control;
    }

    // ---- Catalogue ------------------------------------------------------------

    public function testCatalogue(): void
    {
        $this->assertSame('Amazon Web Services', CloudCatalogue::providerLabel('aws'));
        $this->assertSame('nope', CloudCatalogue::providerLabel('nope'));
        $this->assertSame(CloudCatalogue::KIND_IAAS, CloudCatalogue::providerKind('gcp'));
        $this->assertSame(CloudCatalogue::KIND_SAAS, CloudCatalogue::providerKind('github'));
        $this->assertSame(CloudCatalogue::KIND_OTHER, CloudCatalogue::providerKind('nope'));
        $this->assertSame('Subscription ID', CloudCatalogue::identifierLabel('azure'));
        $this->assertSame('Account identifier', CloudCatalogue::identifierLabel('nope'));
        $this->assertSame('111122223333', CloudCatalogue::identifierPlaceholder('aws'));
        $this->assertSame('', CloudCatalogue::identifierPlaceholder('nope'));
        $this->assertSame('uksouth', CloudCatalogue::regionPlaceholder('azure'));
        $this->assertSame('', CloudCatalogue::regionPlaceholder('nope'));
        $this->assertTrue(CloudCatalogue::isOfficeSuite('m365'));
        $this->assertFalse(CloudCatalogue::isOfficeSuite('aws'));
        $this->assertFalse(CloudCatalogue::isOfficeSuite('nope'));

        $this->assertSame('storage', CloudCatalogue::category('bucket'));
        $this->assertNull(CloudCatalogue::category('nope'));
        $this->assertSame('Object storage bucket', CloudCatalogue::typeLabel('bucket'));
        $this->assertSame('nope', CloudCatalogue::typeLabel('nope'));
        $this->assertSame('Identity & secrets', CloudCatalogue::categoryLabel('identity'));
        $this->assertContains('bucket', CloudCatalogue::typesIn('storage'));
        $this->assertSame(['instance'], CloudCatalogue::osTypes());
        $this->assertTrue(CloudCatalogue::hasOs('instance'));
        $this->assertFalse(CloudCatalogue::hasOs('bucket'));
        $grouped = CloudCatalogue::groupedTypeOptions();
        $this->assertSame('Object storage bucket', $grouped['Storage']['bucket']);
        $this->assertSame(count(CloudCatalogue::typeOptions()), array_sum(array_map('count', $grouped)));
        $this->assertSame(array_keys(CloudCatalogue::categoryOptions()), array_values(array_unique(array_map([CloudCatalogue::class, 'category'], array_keys(CloudCatalogue::typeOptions())))));
        $this->assertSame('Cloud accounts', array_values(CloudCatalogue::scopeOptions())[0]);
    }

    // ---- Posture (pure) --------------------------------------------------------

    public function testAssetPostureMethods(): void
    {
        $checks = [
            1 => new CloudCheck(1, 'CTL-0101', 'Public access blocked', CloudCheck::MANUAL, ['bucket']),
            2 => new CloudCheck(2, 'CTL-0102', 'Encrypted at rest', CloudCheck::ENCRYPTION, null),
            3 => new CloudCheck(3, 'CTL-0103', 'Supported OS', CloudCheck::OS_SUPPORT, null),
            4 => new CloudCheck(4, 'CTL-0104', 'Root MFA', CloudCheck::MANUAL, [], true),
        ];
        $bucket = CloudPosture::evaluateAsset($checks, 'bucket', [1 => 'pass'], null, null, 'yes', '2026-10-28');
        $this->assertSame(CloudPosture::STATE_COMPLIANT, $bucket['state']);
        $this->assertSame([1, 2], array_keys($bucket['items']), 'the OS check skips buckets and the account check skips assets');
        $this->assertSame('Encrypted at rest', $bucket['items'][2]['detail']);

        $failing = CloudPosture::evaluateAsset($checks, 'bucket', [1 => 'fail'], null, null, 'no');
        $this->assertSame(CloudPosture::STATE_NON_COMPLIANT, $failing['state']);
        $this->assertSame(['Public access blocked failed', 'Not encrypted at rest'], array_values($failing['failures']));

        $unknown = CloudPosture::evaluateAsset($checks, 'bucket', [1 => 'maybe'], null, null, 'unknown');
        $this->assertSame(CloudPosture::STATE_NOT_ASSESSED, $unknown['state']);
        $this->assertCount(2, $unknown['unknown']);

        $old = CloudPosture::evaluateAsset($checks, 'instance', [], 'Ubuntu', '18.04 LTS', 'yes', '2026-10-28');
        $this->assertSame(CloudPosture::RESULT_FAIL, $old['items'][3]['result']);
        $this->assertStringContainsString('security updates ended 31 May 2023', $old['items'][3]['detail']);
        $new = CloudPosture::evaluateAsset($checks, 'instance', [], 'Ubuntu', '24.04 LTS', 'yes', '2026-10-28');
        $this->assertSame(CloudPosture::STATE_COMPLIANT, $new['state']);
        $this->assertStringContainsString('until', $new['items'][3]['detail']);
        $noOs = CloudPosture::evaluateAsset($checks, 'instance', [], null, null, 'yes');
        $this->assertSame('No operating system recorded', $noOs['items'][3]['detail']);
        $odd = CloudPosture::evaluateAsset($checks, 'instance', [], 'Plan 9', '4', 'yes');
        $this->assertSame('No end-of-support date known for Plan 9 4', $odd['items'][3]['detail']);

        $account = CloudPosture::evaluateAccount($checks, [4 => 'fail']);
        $this->assertSame([4], array_keys($account['items']));
        $this->assertSame(CloudPosture::STATE_NON_COMPLIANT, $account['state']);
        $this->assertSame(CloudPosture::STATE_COMPLIANT, CloudPosture::evaluateAccount([], [])['state'], 'no checks: nothing required');
    }

    public function testCheckScopeHelpers(): void
    {
        $os = new CloudCheck(1, 'CTL-1', 'OS', CloudCheck::OS_SUPPORT, null, true);
        $this->assertSame(['instance'], $os->assetTypes());
        $this->assertFalse($os->appliesToAccounts(), 'only manual checks are recorded on accounts');
        $this->assertFalse($os->appliesToAsset('bucket'));
        $this->assertSame('Supported OS (cloud)', $os->shortLabel());
        $this->assertSame([], (new CloudCheck(1, 'CTL-1', 'OS', CloudCheck::OS_SUPPORT, ['bucket']))->assetTypes());
        $this->assertSame('Encrypted at rest', (new CloudCheck(2, 'CTL-2', 'Enc', CloudCheck::ENCRYPTION))->shortLabel());
        $manual = new CloudCheck(3, 'CTL-3', 'Root MFA', CloudCheck::MANUAL, [], true);
        $this->assertSame([], $manual->assetTypes());
        $this->assertTrue($manual->appliesToAccounts());
        $this->assertSame('Root MFA', $manual->shortLabel());
    }

    // ---- Control: cloud scope ------------------------------------------------

    public function testControlCloudScopeIsNormalised(): void
    {
        $this->assertNull(Control::cloudScopeValue([]), 'none ticked: every resource type');
        $this->assertNull(Control::cloudScopeValue(array_keys(CloudCatalogue::typeOptions())), 'all types: every resource type');
        $this->assertSame('account', Control::cloudScopeValue(['account']));
        $this->assertSame('account,bucket', Control::cloudScopeValue(['bucket', 'account', 'nope']), 'catalogue order, unknown tokens dropped');

        $mfa = $this->makeCheck(['title' => 'Root MFA', 'cloudScopeList' => ['account']]);
        $this->assertSame('account', $mfa->cloud_scope);
        $this->assertSame('Cloud accounts', $mfa->getCloudScopeLabel());
        $check = CloudChecks::find($mfa->id);
        $this->assertSame([], $check->types);
        $this->assertTrue($check->accounts);
        $this->assertArrayHasKey($mfa->id, CloudChecks::forAccounts());
        $this->assertArrayNotHasKey($mfa->id, CloudChecks::forAssets());

        $os = $this->makeCheck(['title' => 'Cloud OS', 'cloud_check' => Control::CHECK_OS_SUPPORT, 'cloudScopeList' => '']);
        $this->assertNull($os->cloud_scope);
        $this->assertSame('Instances', $os->getCloudScopeLabel());
        $enc = $this->makeCheck(['title' => 'Cloud encryption', 'cloud_check' => Control::CHECK_ENCRYPTION]);
        $this->assertSame('All cloud asset types', $enc->getCloudScopeLabel());

        // Computed checks cannot apply to accounts; no cloud check clears the scope
        $bad = new Control(['title' => 'Bad', 'status' => 'implemented', 'control_type' => 'preventive', 'nature' => 'technical',
            'cloud_check' => Control::CHECK_ENCRYPTION, 'cloudScopeList' => ['account', 'bucket']]);
        $this->assertFalse($bad->validate());
        $this->assertArrayHasKey('cloudScopeList', $bad->getErrors());
        $mfa->cloud_check = '';
        $this->assertTrue($mfa->save(), json_encode($mfa->getErrors()));
        $this->assertNull($mfa->cloud_check);
        $this->assertNull($mfa->cloud_scope);
        $this->assertArrayNotHasKey($mfa->id, CloudChecks::all(), 'saving a control resets the cache');

        $this->assertSame('Cloud accounts, Object storage bucket', (new Control())->formatAuditValue('cloud_scope', 'account,bucket'));
        $form = Control::findOne($os->id);
        $form->populateLinks();
        $this->assertSame([], $form->cloudScopeList);
        $this->assertSame(1, (int) Control::find()->cloudChecks()->andWhere(['control.id' => [$os->id, $mfa->id]])->count());

        $os->delete();
        $this->assertNull(CloudChecks::find($os->id), 'deleting a control resets the cache');
    }

    // ---- Models ----------------------------------------------------------------

    public function testAccountValidationAndReferences(): void
    {
        $account = $this->makeAccount(['owner_id' => '']);
        $this->assertMatchesRegularExpression('/^ACC-\d{4,}$/', $account->reference);
        $this->assertSame('Production (AWS)', $account->getTitleValue());
        $this->assertSame('Amazon Web Services · 111122223333', $account->getProviderSummary());
        $this->assertSame('Production', $account->getEnvironmentLabel());
        $this->assertSame('Active', $account->getStatusLabel());
        $this->assertTrue($account->isInService());
        $this->assertSame('/cloud/account/view', CloudAccount::viewRoute());

        $dupe = new CloudAccount(['name' => 'Dupe', 'provider' => 'aws', 'account_identifier' => '111122223333', 'environment' => 'production',
            'status' => 'active', 'criticality' => 'low', 'data_classification' => 'internal']);
        $this->assertFalse($dupe->validate());
        $this->assertArrayHasKey('account_identifier', $dupe->getErrors());
        $dupe->provider = 'gcp';
        $this->assertTrue($dupe->validate(), 'unique per provider');
        $dupe->provider = 'nope';
        $dupe->environment = 'nope';
        $this->assertFalse($dupe->validate());
        $this->assertArrayHasKey('provider', $dupe->getErrors());
        $this->assertArrayHasKey('environment', $dupe->getErrors());
        $this->assertSame('Production', $account->formatAuditValue('environment', 'production'));
    }

    public function testAssetValidationAndDefaults(): void
    {
        $account = $this->makeAccount(['environment' => 'staging']);
        $asset = $this->makeAsset($account, ['public_ips' => "203.0.113.10,  2001:db8::10\n203.0.113.10", 'public_endpoints' => 'portal.example.com https://api.example.com/v1',
            'os' => 'Ubuntu', 'os_version' => '24.04', 'region' => 'eu-west-2']);
        $this->assertMatchesRegularExpression('/^CLD-\d{4,}$/', $asset->reference);
        $this->assertSame('staging', $asset->environment, 'environment defaults to the account\'s');
        $this->assertSame(CloudAsset::ENCRYPTION_UNKNOWN, $asset->encryption_at_rest);
        $this->assertSame('203.0.113.10, 2001:db8::10', $asset->public_ips);
        $this->assertSame(['portal.example.com', 'https://api.example.com/v1'], $asset->getEndpointList());
        $this->assertNull($asset->os, 'only instances keep an OS');
        $this->assertSame('aws', $asset->getProvider());
        $this->assertSame('Object storage bucket', $asset->getTypeLabel());
        $this->assertSame('Storage', $asset->getCategoryLabel());
        $this->assertSame('Staging', $asset->getEnvironmentLabel());
        $this->assertSame('Active', $asset->getStatusLabel());
        $this->assertFalse($asset->isInternetFacing());
        $this->assertTrue($asset->isInService());
        $this->assertSame('Yes', $asset->formatAuditValue('internet_facing', 1));

        $bad = new CloudAsset(['account_id' => $account->id, 'name' => 'x', 'resource_type' => 'nope', 'status' => 'active', 'criticality' => 'low',
            'data_classification' => 'internal', 'public_ips' => '10.0.0.256', 'public_endpoints' => 'not a host!', 'region' => '<eu>',
            'source' => 'aws']);
        $this->assertFalse($bad->validate());
        foreach (['resource_type', 'public_ips', 'public_endpoints', 'region', 'source_id'] as $attribute) {
            $this->assertArrayHasKey($attribute, $bad->getErrors(), $attribute);
        }
        $this->assertTrue(CloudAsset::isEndpoint('*.example.com'));
        $this->assertFalse(CloudAsset::isEndpoint('https://'));
        $this->assertFalse(CloudAsset::isEndpoint('localhost'));

        // Resource ids are unique per account
        $this->makeAsset($account, ['name' => 'a', 'resource_id' => 'arn:aws:s3:::a']);
        $dupe = new CloudAsset(['account_id' => $account->id, 'name' => 'b', 'resource_type' => 'bucket', 'resource_id' => 'arn:aws:s3:::a',
            'status' => 'active', 'criticality' => 'low', 'data_classification' => 'internal']);
        $this->assertFalse($dupe->validate());
        $this->assertArrayHasKey('resource_id', $dupe->getErrors());
        $dupe->account_id = $this->makeAccount(['account_identifier' => '444455556666'])->id;
        $this->assertTrue($dupe->validate(), json_encode($dupe->getErrors()));

        $orphan = new CloudAsset(['account_id' => 999999, 'name' => 'x', 'resource_type' => 'bucket', 'status' => 'active', 'criticality' => 'low', 'data_classification' => 'internal']);
        $this->assertFalse($orphan->validate());
        $this->assertArrayHasKey('account_id', $orphan->getErrors());
        $this->assertArrayHasKey('environment', $orphan->getErrors(), 'no account: no environment to default to');
    }

    public function testCheckResultsAreSavedAndAudited(): void
    {
        $public = $this->makeCheck(['title' => 'Bucket public access blocked', 'cloudScopeList' => ['bucket']]);
        $mfa = $this->makeCheck(['title' => 'Root MFA', 'cloudScopeList' => ['account']]);
        $account = $this->makeAccount(['checks' => [$mfa->id => 'pass']]);
        $this->assertSame('pass', $account->getCheckResults()[$mfa->id]);
        $this->assertTrue($account->getPosture()['compliant']);
        $this->assertSame([$mfa->id], array_map(static fn($c) => $c->id, $account->controls));

        $asset = $this->makeAsset($account, ['checks' => [$public->id => 'pass']]);
        $this->assertSame(CloudPosture::STATE_COMPLIANT, $asset->getPosture()['state']);
        $reloaded = CloudAsset::findOne($asset->id);
        $reloaded->checks = [$public->id => 'fail'];
        $this->assertTrue($reloaded->save(), json_encode($reloaded->getErrors()));
        $this->assertSame(CloudPosture::STATE_NON_COMPLIANT, $reloaded->getPosture()['state']);
        $audit = AuditLog::find()->where(['entity_type' => 'cloud_asset', 'entity_id' => $asset->id, 'action' => 'update'])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($audit, 'a change to check results alone is audited');
        $this->assertStringContainsString('Bucket public access blocked: Fail', json_encode($audit->getChangesArray()['checks']));
        $this->assertSame([$public->id], array_map(static fn($c) => $c->id, $reloaded->controls));

        $reloaded->checks = [$public->id => ''];
        $this->assertTrue($reloaded->save());
        $this->assertArrayNotHasKey($public->id, $reloaded->getCheckResults());
        $reloaded->checks = [$mfa->id => 'pass'];
        $this->assertFalse($reloaded->validate(), 'an account check cannot be recorded on an asset');
        $reloaded->checks = 'junk';
        $this->assertTrue($reloaded->validate());
        $this->assertSame([], $reloaded->checks);

        $form = CloudAccount::findOne($account->id);
        $form->populateLinks();
        $this->assertSame([$mfa->id => 'pass'], $form->checks);
    }

    public function testPostureQueriesMatchPhpLogic(): void
    {
        $public = $this->makeCheck(['title' => 'Bucket public access blocked', 'cloudScopeList' => ['bucket']]);
        $enc = $this->makeCheck(['title' => 'Encryption', 'cloud_check' => Control::CHECK_ENCRYPTION, 'cloudScopeList' => ['bucket', 'database']]);
        $os = $this->makeCheck(['title' => 'OS', 'cloud_check' => Control::CHECK_OS_SUPPORT, 'cloudScopeList' => ['instance']]);
        $mfa = $this->makeCheck(['title' => 'Root MFA', 'cloudScopeList' => ['account']]);
        $account = $this->makeAccount(['checks' => [$mfa->id => 'fail']]);
        $other = $this->makeAccount(['account_identifier' => '444455556666', 'checks' => [$mfa->id => 'pass']]);
        $third = $this->makeAccount(['account_identifier' => '777788889999']);
        $today = date('Y-m-d');
        $assets = [
            'good' => $this->makeAsset($account, ['name' => 'good', 'encryption_at_rest' => 'yes', 'checks' => [$public->id => 'pass']]),
            'open' => $this->makeAsset($account, ['name' => 'open', 'encryption_at_rest' => 'yes', 'internet_facing' => 1, 'checks' => [$public->id => 'fail']]),
            'plain' => $this->makeAsset($account, ['name' => 'plain', 'resource_type' => 'database', 'encryption_at_rest' => 'no']),
            'unknown' => $this->makeAsset($account, ['name' => 'unknown', 'encryption_at_rest' => 'unknown']),
            'old' => $this->makeAsset($account, ['name' => 'old', 'resource_type' => 'instance', 'os' => 'Windows Server', 'os_version' => '2012 R2',
                'last_seen_at' => date('Y-m-d H:i:s', strtotime('-120 days'))]),
            'vm' => $this->makeAsset($account, ['name' => 'vm', 'resource_type' => 'instance', 'os' => 'Ubuntu', 'os_version' => '24.04',
                'last_seen_at' => date('Y-m-d H:i:s', strtotime('-40 days'))]),
            'vpc' => $this->makeAsset($account, ['name' => 'vpc', 'resource_type' => 'vpc']),
            'gone' => $this->makeAsset($account, ['name' => 'gone', 'resource_type' => 'vpc', 'status' => 'decommissioned']),
        ];
        $ids = array_map(static fn(CloudAsset $a) => $a->id, $assets);
        $names = static fn($query) => array_keys(array_intersect($ids, array_map('intval', $query->andWhere(['cloud_asset.id' => $ids])->select('cloud_asset.id')->column())));

        $byState = [];
        foreach ($assets as $name => $asset) {
            if ($asset->isInService()) {
                $byState[CloudAsset::findOne($asset->id)->getPosture()['state']][] = $name;
            }
        }
        $this->assertSame($byState[CloudPosture::STATE_COMPLIANT], $names(CloudAsset::find()->inService()->compliant()));
        $this->assertSame($byState[CloudPosture::STATE_NON_COMPLIANT], $names(CloudAsset::find()->inService()->nonCompliant()));
        $this->assertSame($byState[CloudPosture::STATE_NOT_ASSESSED], $names(CloudAsset::find()->inService()->notAssessed()));
        $this->assertSame(['good', 'vm', 'vpc'], $byState[CloudPosture::STATE_COMPLIANT]);

        $this->assertSame(['plain'], $names(CloudAsset::find()->checkResult(CloudChecks::find($enc->id), 'fail')));
        $this->assertSame(['good', 'open'], $names(CloudAsset::find()->checkResult(CloudChecks::find($enc->id), 'pass')));
        $this->assertSame(['unknown'], $names(CloudAsset::find()->checkResult(CloudChecks::find($enc->id), 'unknown')));
        $this->assertSame(['unknown'], $names(CloudAsset::find()->checkResult(CloudChecks::find($public->id), 'unknown')), 'the bucket check does not apply to the database');
        $this->assertSame(['old'], $names(CloudAsset::find()->checkResult(CloudChecks::find($os->id), 'fail')));
        $this->assertSame(['vm'], $names(CloudAsset::find()->checkResult(CloudChecks::find($os->id), 'pass')));
        $this->assertSame([], $names(CloudAsset::find()->checkResult(CloudChecks::find($os->id), 'unknown')));
        $this->assertSame([], $names(CloudAsset::find()->checkResult(CloudChecks::find($mfa->id), 'fail')), 'account checks never match assets');
        $this->assertSame(['old'], $names(CloudAsset::find()->osSupport(OsLifecycle::UNSUPPORTED)));
        $this->assertContains('vpc', $names(CloudAsset::find()->osSupport(OsLifecycle::UNKNOWN)));
        $this->assertSame(['old', 'vm'], $names(CloudAsset::find()->stale($today)));
        $this->assertSame(['old'], $names(CloudAsset::find()->missing($today)));
        $this->assertSame(['open'], $names(CloudAsset::find()->internetFacing()));

        // Accounts
        $accountIds = [$account->id => 'fail', $other->id => 'pass', $third->id => 'unknown'];
        $accounts = static fn($query) => array_map('intval', $query->andWhere(['cloud_account.id' => array_keys($accountIds)])->select('cloud_account.id')->column());
        $this->assertSame([$other->id], $accounts(CloudAccount::find()->compliant()));
        $this->assertSame([$account->id], $accounts(CloudAccount::find()->nonCompliant()));
        $this->assertSame([$third->id], $accounts(CloudAccount::find()->notAssessed()));
        $this->assertSame([$account->id], $accounts(CloudAccount::find()->checkResult(CloudChecks::find($mfa->id), 'fail')));
        $this->assertSame([], $accounts(CloudAccount::find()->checkResult(CloudChecks::find($enc->id), 'fail')), 'asset checks never match accounts');
        $this->assertSame(CloudPosture::STATE_NOT_ASSESSED, $third->getPosture()['state']);
    }

    public function testQueriesWithoutChecks(): void
    {
        $account = $this->makeAccount();
        $asset = $this->makeAsset($account);
        $this->assertSame([], CloudChecks::all());
        $this->assertTrue(CloudAsset::find()->compliant()->andWhere(['cloud_asset.id' => $asset->id])->exists(), 'no checks: everything is compliant');
        $this->assertFalse(CloudAsset::find()->nonCompliant()->andWhere(['cloud_asset.id' => $asset->id])->exists());
        $this->assertTrue(CloudAccount::find()->compliant()->andWhere(['cloud_account.id' => $account->id])->exists());
        $this->assertFalse(CloudAccount::find()->notAssessed()->andWhere(['cloud_account.id' => $account->id])->exists());
    }

    public function testLastSeenTones(): void
    {
        $today = '2026-10-28';
        $asset = new CloudAsset();
        $this->assertNull($asset->getLastSeenTone($today), 'not synced');
        $this->assertSame('', Badge::lastSeen($asset, $today));
        $asset->last_seen_at = '2026-09-01 09:00:00';
        $this->assertSame('warning', $asset->getLastSeenTone($today));
        $asset->source = 'aws';
        $this->assertStringContainsString('No sync from Amazon Web Services for more than 30 days', Badge::lastSeen($asset, $today));
        $asset->last_seen_at = '2026-06-01 09:00:00';
        $this->assertSame('danger', $asset->getLastSeenTone($today));
        $this->assertTrue($asset->isMissing($today));
        $this->assertStringContainsString('more than 90 days', Badge::lastSeen($asset, $today));
        Yii::$app->params['cloud.staleCriticalDays'] = 10;
        try {
            $this->assertSame(CloudAsset::staleDays(), CloudAsset::staleCriticalDays());
        } finally {
            unset(Yii::$app->params['cloud.staleCriticalDays']);
        }
        $this->assertSame('Amazon Web Services', $asset->getSourceLabel());
        $asset->source = null;
        $this->assertNull($asset->getSourceLabel());
    }

    // ---- Links, attachments, ATT&CK and intel --------------------------------------

    public function testLinkDefinitions(): void
    {
        foreach ([[Risk::class, 'risk_cloud_asset', 'Affected cloud assets'], [Incident::class, 'incident_cloud_asset', 'Affected cloud assets'],
                     [Decision::class, 'decision_cloud_asset', 'Related cloud assets']] as [$class, $table, $label]) {
            $definition = (new $class())->linkDefinitions()['cloudAssetIds'];
            $this->assertSame($table, $definition['table']);
            $this->assertSame('cloud_asset_id', $definition['other']);
            $this->assertSame(CloudAsset::class, $definition['class']);
            $this->assertSame($label, (new $class())->getAttributeLabel('cloudAssetIds'));
        }
        $asset = $this->makeAsset($this->makeAccount());
        $this->assertSame([], $asset->risks);
        $this->assertSame([], $asset->incidents);
        $this->assertSame([], $asset->decisions);
    }

    public function testAttachmentsAndAuditEntities(): void
    {
        $this->assertContains('cloud_asset', Attachment::ENTITY_TYPES);
        $this->assertContains('cloud_account', Attachment::ENTITY_TYPES);
        $this->assertSame(CloudAsset::class, Attachment::ownerClass('cloud_asset'));
        $account = $this->makeAccount();
        $this->assertSame($account->id, Attachment::findOwner('cloud_account', $account->id)->id);
        $this->assertSame('Cloud asset', AuditLog::entityLabels()['cloud_asset']);
    }

    public function testAttackPlatforms(): void
    {
        $aws = $this->makeAccount();
        $this->assertSame(['IaaS'], CloudAttack::accountPlatforms($aws));
        $this->assertSame(['Linux', 'IaaS'], CloudAttack::assetPlatforms($this->makeAsset($aws, ['name' => 'vm', 'resource_type' => 'instance', 'os' => 'Amazon Linux', 'os_version' => '2023'])));
        $this->assertSame(['IaaS', 'Containers'], CloudAttack::assetPlatforms($this->makeAsset($aws, ['name' => 'eks', 'resource_type' => 'container_cluster'])));
        $this->assertSame(['IaaS', 'Identity Provider'], CloudAttack::assetPlatforms($this->makeAsset($aws, ['name' => 'role', 'resource_type' => 'iam_role'])));

        $m365 = $this->makeAccount(['provider' => 'm365', 'account_identifier' => 'example.onmicrosoft.com']);
        $this->assertSame(['SaaS', 'Office Suite', 'Identity Provider'], CloudAttack::accountPlatforms($m365));
        $github = $this->makeAccount(['provider' => 'github', 'account_identifier' => 'example-org']);
        $this->assertSame(['SaaS'], CloudAttack::assetPlatforms($this->makeAsset($github, ['name' => 'repo', 'resource_type' => 'repository'])));
        $this->assertSame(['SaaS', 'Identity Provider'], CloudAttack::assetPlatforms($this->makeAsset($github, ['name' => 'app', 'resource_type' => 'iam_user'])));

        $other = $this->makeAccount(['provider' => 'other', 'account_identifier' => 'droplets']);
        $this->assertSame(['SaaS'], CloudAttack::accountPlatforms($other));
        $this->assertSame(['IaaS'], CloudAttack::assetPlatforms($this->makeAsset($other, ['name' => 'db', 'resource_type' => 'database'])));
    }

    public function testIntelRecognisesOwnCloudAddresses(): void
    {
        $account = $this->makeAccount();
        $alb = $this->makeAsset($account, ['name' => 'alb', 'resource_type' => 'load_balancer', 'public_ips' => '203.0.113.10, 203.0.113.11']);
        $this->makeAsset($account, ['name' => 'near-miss', 'resource_type' => 'public_ip', 'public_ips' => '203.0.113.100']);
        $context = LocalContext::for(Indicator::parse('203.0.113.10'), []);
        $this->assertSame([$alb->id], array_map(static fn(CloudAsset $a) => $a->id, $context->cloudAssets));
        $this->assertSame([], LocalContext::for(Indicator::parse('AS64500'), [])->cloudAssets);
    }
}
