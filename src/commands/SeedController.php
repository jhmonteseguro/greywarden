<?php

namespace app\commands;

use app\components\Actor;
use app\components\Rbac;
use app\models\Lookup;
use app\models\User;
use app\modules\attack\models\AttackMitigation;
use app\modules\attack\models\AttackTechnique;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\control\models\ControlTest;
use app\modules\control\models\FrameworkRequirement;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\incident\models\IncidentTimeline;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\IntelIndicator;
use app\modules\intel\models\IntelNote;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\ActiveRecord;
use yii\helpers\Console;

/**
 * Demo data for designers / evaluation. Separate from migrations and optional.
 *
 *   php yii seed/demo            # refuses if domain data exists
 *   php yii seed/demo --reset=1  # wipes risks/decisions/incidents/devices/controls (and intel notes / blocklist) first
 *
 * Threat intel demo data only uses documentation ranges (192.0.2.0/24, 198.51.100.0/24,
 * 203.0.113.0/24) and a documentation ASN, so investigating it never leaves the network.
 */
class SeedController extends Controller
{
    /** CIA properties affected (C, I, A), by incident number (INC-00nn); unlisted = none recorded */
    private const CIA_INCIDENTS = [
        1 => 'C', 2 => 'IA', 3 => 'C', 4 => 'C', 5 => 'A', 6 => 'I', 7 => 'C', 9 => 'I', 10 => 'C', 11 => 'I',
        12 => 'C', 13 => 'C', 14 => 'CI', 15 => 'CIA', 16 => 'C', 17 => 'C', 19 => 'C', 20 => 'A',
    ];

    /** MITRE ATT&CK techniques observed, by incident number (INC-00nn) */
    private const ATTACK_INCIDENTS = [
        1 => ['T1566', 'T1684.001', 'T1583.001'],                 // CEO impersonation
        2 => ['T1566.001', 'T1553.005', 'T1204.002', 'T1486'],    // ransomware via ISO attachment
        4 => ['T1078', 'T1114.002'],                              // former employee in HR mailbox
        5 => ['T1498', 'T1499.002'],                              // DDoS
        6 => ['T1091', 'T1204.002'],                              // USB malware
        8 => ['T1595.002', 'T1190', 'T1133'],                     // VPN appliance exploitation attempt
        9 => ['T1586.002', 'T1684.001', 'T1657'],                 // invoice fraud via supplier mailbox
        11 => ['T1219'],                                          // unauthorised remote-access tool
        12 => ['T1110.004'],                                      // credential stuffing
        13 => ['T1530'],                                          // public S3 bucket
        14 => ['T1566.002', 'T1528', 'T1550.001', 'T1114.002'],   // malicious OAuth consent
        15 => ['T1059.001', 'T1105', 'T1021.002'],                // PowerShell on file server
        16 => ['T1566.002', 'T1557'],                             // quishing (AiTM)
        18 => ['T1133', 'T1021.001'],                             // exposed RDP
        20 => ['T1110.003', 'T1133'],                             // VPN password spraying
    ];

    /** ATT&CK techniques that would realise each risk, by risk number (RSK-00nn) */
    private const ATTACK_RISKS = [
        1 => ['T1566.001', 'T1021.002', 'T1078.002', 'T1486', 'T1490'],
        2 => ['T1566.002', 'T1557', 'T1111', 'T1621', 'T1078.004'],
        3 => ['T1190', 'T1133'],
        5 => ['T1199'],
        6 => ['T1213', 'T1567.002', 'T1052.001'],
        8 => ['T1190', 'T1210', 'T1068'],
        9 => ['T1078.002', 'T1003.001', 'T1550.002'],
        11 => ['T1567.002'],
        12 => ['T1498', 'T1499.002'],
        15 => ['T1195.002', 'T1552.001'],
        17 => ['T1530'],
        18 => ['T1566', 'T1204'],
        19 => ['T1078.001', 'T1046', 'T1210'],
        20 => ['T1586.002', 'T1684.001', 'T1657'],
        24 => ['T1199', 'T1133', 'T1219'],
    ];

    /** ATT&CK mitigations each decision puts in place, by decision number (DEC-00nn) */
    private const ATTACK_DECISIONS = [
        1 => ['M1049', 'M1040'],   // Defender for Endpoint
        2 => ['M1032'],            // phishing-resistant MFA
        4 => ['M1051'],            // migrate off Server 2012 R2
        6 => ['M1042'],            // retire on-prem Exchange
        8 => ['M1051', 'M1016'],   // 72h patch SLA (proposed)
        9 => ['M1037'],            // DDoS protection (proposed)
        10 => ['M1041'],           // BitLocker
        11 => ['M1030'],           // IoT segmentation
        15 => ['M1018', 'M1026'],  // ERP access reviews
    ];

    /** @var bool wipe existing domain data first */
    public $reset = false;

    private array $users = [];
    private array $lookups = [];

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['reset']);
    }

    public function actionDemo(): int
    {
        mt_srand(20261009);
        $hasData = Risk::find()->exists() || Device::find()->exists() || Incident::find()->exists() || Decision::find()->exists() || CloudAccount::find()->exists();
        if ($hasData && !$this->reset) {
            $this->stderr("Domain data already exists. Re-run with --reset=1 to wipe and reseed.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if ($this->reset) {
            // The wipe commits on its own: resetting AUTO_INCREMENT is DDL, which MariaDB commits
            // implicitly, so it can't share a transaction with the seeding below.
            try {
                Yii::$app->db->transaction(fn() => $this->wipe());
                $this->resetSequences();
            } catch (\Throwable $e) {
                $this->stderr('Wiping existing data failed: ' . $e->getMessage() . "\n", Console::FG_RED);
                return ExitCode::SOFTWARE;
            }
        }

        $tx = Yii::$app->db->beginTransaction();
        try {
            $this->seedUsers();
            $this->seedLookups();
            $checks = $this->seedDeviceChecks();
            $devices = $this->seedDevices($checks);
            $decisions = $this->seedDecisions();
            $incidents = $this->seedIncidents($devices);
            $risks = $this->seedRisks($devices, $incidents, $decisions);
            $this->linkDecisions($decisions, $incidents);
            $controls = $this->seedControls($checks, $risks, $decisions, $incidents);
            [$accounts, $assets, $cloudControls] = $this->seedCloud($checks, $risks, $decisions, $incidents);
            $blocks = $this->seedIntel($incidents);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            Actor::override(null);
            $this->stderr('Seeding failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", Console::FG_RED);
            if ($this->reset) {
                $this->stderr("The existing domain data was already removed; fix the error and re-run.\n", Console::FG_YELLOW);
            }
            return ExitCode::SOFTWARE;
        }
        Actor::override(null);
        Yii::$app->cache->flush();

        $this->stdout(sprintf("Seeded %d risks, %d controls, %d decisions, %d incidents, %d devices, %d cloud accounts, %d cloud assets, %d blocklist entries.\n",
            count($risks), $controls + $cloudControls, count($decisions), count($incidents), count($devices), $accounts, $assets, $blocks), Console::FG_GREEN);
        $this->stdout(sprintf("Demo users (password from DEMO_PASSWORD): analyst, jpatel (analysts), viewer (read-only).\n"));
        return ExitCode::OK;
    }

    // ---------------------------------------------------------------------

    private function wipe(): void
    {
        $db = Yii::$app->db;
        foreach (['risk_device', 'risk_incident', 'risk_decision', 'decision_incident', 'incident_device', 'incident_timeline',
                     'risk_cloud_asset', 'incident_cloud_asset', 'decision_cloud_asset', 'cloud_asset_control', 'cloud_account_control',
                     'incident_attack_technique', 'risk_attack_technique', 'decision_attack_mitigation',
                     'device_control', 'incident_control', 'risk_control', 'decision_control', 'control_attack_mitigation',
                     'control_framework_requirement', 'control_test'] as $t) {
            $db->createCommand()->delete($t)->execute();
        }
        $db->createCommand()->update('decision', ['superseded_by_id' => null])->execute();
        foreach (['intel_blocklist', 'intel_note', 'intel_indicator', 'risk', 'incident', 'decision', 'device', 'cloud_asset', 'cloud_account', 'control'] as $t) {
            $db->createCommand()->delete($t)->execute();
        }
        $db->createCommand()->update('framework_requirement', ['applicable' => 1, 'justification' => null, 'updated_at' => null, 'updated_by' => null])->execute();
        $db->createCommand()->delete('audit_log', ['entity_type' => ['risk', 'incident', 'decision', 'device', 'cloud_account', 'cloud_asset', 'blocklist', 'indicator', 'control', 'requirement']])->execute();
        DeviceChecks::reset();
        CloudChecks::reset();
        $this->stdout("Existing domain data removed.\n");
    }

    /** Restarts ids at 1 on the wiped tables. DDL, so call it outside any transaction. */
    private function resetSequences(): void
    {
        $db = Yii::$app->db;
        if ($db->driverName === 'mysql') {
            foreach (['risk', 'incident', 'decision', 'device', 'cloud_account', 'cloud_asset', 'incident_timeline', 'intel_blocklist', 'intel_note', 'intel_indicator', 'control', 'control_test'] as $t) {
                $db->createCommand("ALTER TABLE {{{$t}}} AUTO_INCREMENT = 1")->execute();
            }
        }
    }

    private function seedUsers(): void
    {
        $admin = User::find()->innerJoin('auth_assignment a', 'a.user_id = user.id')->where(['a.item_name' => Rbac::ROLE_ADMIN])->orderBy('user.id')->one();
        $this->users['admin'] = $admin?->id;
        $password = gw_env('DEMO_PASSWORD', 'Greywarden!Demo1');
        $demo = [
            ['analyst', 'alex.morgan@example.com', 'Alex Morgan', 'Security Analyst', Rbac::ROLE_ANALYST],
            ['jpatel', 'jaya.patel@example.com', 'Jaya Patel', 'IT Security Engineer', Rbac::ROLE_ANALYST],
            ['viewer', 'sam.clarke@example.com', 'Sam Clarke', 'Finance Director', Rbac::ROLE_VIEWER],
        ];
        foreach ($demo as [$username, $email, $name, $title, $role]) {
            $user = User::findByUsername($username);
            if (!$user) {
                $user = User::createUser($username, $email, $name, $password, $role, false);
                $user->job_title = $title;
                $user->save(false);
                $this->stdout("  user {$username} ({$role}) created\n");
            }
            $this->users[$username] = $user->id;
        }
        $this->users['admin'] ??= $this->users['analyst'];
    }

    private function seedLookups(): void
    {
        $now = date('Y-m-d H:i:s');
        $extra = [
            Lookup::TYPE_DEPARTMENT => ['IT', 'Finance', 'HR', 'Operations', 'Sales', 'Legal', 'Executive', 'Engineering'],
            Lookup::TYPE_LOCATION => ['London HQ', 'Manchester Office', 'Remote / Home', 'Data Centre - Slough', 'Cloud - AWS eu-west-2'],
        ];
        foreach ($extra as $type => $names) {
            foreach ($names as $i => $name) {
                if (!Lookup::find()->where(['type' => $type, 'name' => $name])->exists()) {
                    Yii::$app->db->createCommand()->insert('lookup', [
                        'type' => $type, 'name' => $name, 'sort_order' => ($i + 1) * 10, 'is_active' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ])->execute();
                }
            }
        }
        foreach (Lookup::find()->all() as $l) {
            $this->lookups[$l->type][$l->name] = $l->id;
        }
    }

    private function lookup(string $type, string $name): int
    {
        if (!isset($this->lookups[$type][$name])) {
            throw new \RuntimeException("Missing lookup {$type}/{$name}");
        }
        return $this->lookups[$type][$name];
    }

    /**
     * Catalogue ids for ATT&CK ids; ids missing from the loaded release are skipped.
     * @param class-string<AttackTechnique|AttackMitigation> $class
     */
    private function attackIds(string $class, array $externalIds): array
    {
        if (!$externalIds) {
            return [];
        }
        $ids = $class::find()->select('id')->where(['external_id' => $externalIds])->column();
        if (count($ids) < count($externalIds)) {
            $this->stderr(sprintf("  ATT&CK: %d of %s not in the loaded catalogue (run php yii attack/import)\n",
                count($externalIds) - count($ids), implode(', ', $externalIds)), Console::FG_YELLOW);
        }
        return array_map('intval', $ids);
    }

    private static function d(int $days): string
    {
        return date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
    }

    private static function dt(float $hours): string
    {
        return date('Y-m-d H:i:s', (int) (floor(time() / 60) * 60 + $hours * 3600));
    }

    /**
     * Timestamp of step $s, linear between the surrounding anchors.
     * @param array<int, int> $anchors step => timestamp, ascending by step
     */
    private static function interpolate(array $anchors, int $s): int
    {
        $prev = $pos = $ts = 0;
        foreach ($anchors as $pos => $ts) {
            if ($pos >= $s) {
                break;
            }
            $prev = $pos;
        }
        return $pos === $s ? $ts : (int) ($anchors[$prev] + ($ts - $anchors[$prev]) * ($s - $prev) / ($pos - $prev));
    }

    private function as(string $user): void
    {
        $id = $this->users[$user];
        Actor::override($id, $user);
    }

    private function save(ActiveRecord $model): ActiveRecord
    {
        if (!$model->save()) {
            throw new \RuntimeException(get_class($model) . ' invalid: ' . json_encode($model->getErrors()));
        }
        return $model;
    }

    /** Pretend the record was created at $when (cosmetic, keeps history believable). */
    private function backdate(ActiveRecord $model, string $when): void
    {
        $model::updateAll(['created_at' => $when, 'updated_at' => $when], ['id' => $model->id]);
    }

    // ---- Devices ----------------------------------------------------------

    /**
     * The device-check controls (as created by the posture migration), keyed by the
     * posture column they replaced.
     * @return array<string,int> key => control id
     */
    private function seedDeviceChecks(): array
    {
        $endpoints = ['laptop', 'desktop', 'server', 'vm'];
        $defs = [
            'disk_encrypted' => ['Full-disk encryption on devices', Control::CHECK_MANUAL, array_merge($endpoints, ['mobile', 'tablet']), ['M1041'], ['A.8.1', 'A.8.24']],
            'av_edr_installed' => ['Anti-malware / EDR on endpoints', Control::CHECK_MANUAL, $endpoints, ['M1049', 'M1040'], ['A.8.7', 'CE-MP-1', 'CE-MP-2', 'CC6.8', 'DE.CM-09', 'B2.b']],
            'mdm_enrolled' => ['Devices enrolled in MDM', Control::CHECK_MANUAL, ['laptop', 'desktop', 'mobile', 'tablet'], ['M1028', 'M1033'], ['A.8.1', 'A.8.9']],
            'firewall_enabled' => ['Host firewall enabled', Control::CHECK_MANUAL, $endpoints, ['M1037'], ['A.8.20', 'CE-FW-1', 'CE-FW-7']],
            'patched' => ['Security updates applied promptly', Control::CHECK_PATCH_AGE, null, ['M1051'], ['A.8.8', 'CE-SU-3', 'CE-SU-4', 'CC7.1', 'PR.PS-02', 'B4.d']],
            'os_supported' => ['Supported operating systems', Control::CHECK_OS_SUPPORT, array_merge($endpoints, ['mobile', 'tablet']), ['M1051'], ['A.8.8', 'CE-SU-1', 'CE-SU-2', 'CC7.1', 'PR.PS-02', 'B4.d']],
        ];
        $this->as('jpatel');
        $ids = [];
        foreach ($defs as $key => [$title, $method, $types, $mitigations, $requirements]) {
            $control = Control::find()->where(['title' => $title, 'device_check' => $method])->one() ?? new Control([
                'title' => $title,
                'control_type' => $key === 'av_edr_installed' ? 'detective' : 'preventive',
                'nature' => 'technical',
                'status' => Control::STATUS_IMPLEMENTED,
                'implemented_on' => self::d(-400),
                'device_check' => $method,
                'deviceTypeList' => $types ?? [],
                'check_max_age_days' => $method === Control::CHECK_PATCH_AGE ? 30 : null,
                'mitigationIds' => $this->attackIds(AttackMitigation::class, $mitigations),
                'requirementIds' => $this->requirementIds($requirements),
            ]);
            $this->save($control);
            $ids[$key] = $control->id;
        }
        return $ids;
    }

    /** @return int[] framework requirement ids for "A.8.7" / "CE-UA-4" / "CC6.1" / "PR.AA-01" / "B4.d" style ids */
    private function requirementIds(array $externalIds): array
    {
        return array_map('intval', FrameworkRequirement::find()->select('id')->where(['external_id' => $externalIds])->column());
    }

    /** @param array<string,int> $checks see seedDeviceChecks() */
    private function seedDevices(array $checks): array
    {
        $people = ['Olivia Bennett', 'James Carter', 'Priya Shah', 'Tom Hughes', 'Chloe Evans', 'Daniel Wright', 'Aisha Khan', 'Liam Murphy',
            'Emma Robinson', 'Noah Thompson', 'Grace Walker', 'Mohammed Ali', 'Sophie Turner', 'Harry Wood', 'Isla Campbell', 'Ethan Price',
            'Ava Mitchell', 'Lucas Green', 'Mia Edwards', 'Oscar Hall'];
        $depts = ['IT', 'Finance', 'HR', 'Operations', 'Sales', 'Legal', 'Executive', 'Engineering'];

        // [type, manufacturer, model, os, os_version, count, hostPrefix, criticality, classification]
        $templates = [
            ['laptop', 'Dell', 'Latitude 7440', 'Windows 11 Enterprise', '23H2', 6, 'LT', 'medium', 'confidential'],
            ['laptop', 'Lenovo', 'ThinkPad T14s Gen 4', 'Windows 11 Enterprise', '23H2', 4, 'LT', 'medium', 'confidential'],
            ['laptop', 'Apple', 'MacBook Pro 14" M3', 'macOS', '14.6 Sonoma', 4, 'MB', 'medium', 'confidential'],
            ['laptop', 'HP', 'EliteBook 840 G10', 'Windows 10 Enterprise', '22H2', 2, 'LT', 'medium', 'internal'],
            ['desktop', 'HP', 'EliteDesk 800 G9', 'Windows 11 Enterprise', '23H2', 3, 'DT', 'low', 'internal'],
            ['desktop', 'Dell', 'OptiPlex 7010', 'Windows 11 Enterprise', '22H2', 2, 'DT', 'low', 'internal'],
            ['server', 'Dell', 'PowerEdge R750', 'Windows Server 2022', '21H2', 2, 'SRV', 'critical', 'restricted'],
            ['server', 'HPE', 'ProLiant DL380 Gen10', 'Ubuntu Server', '22.04 LTS', 2, 'SRV', 'high', 'confidential'],
            ['server', 'Dell', 'PowerEdge R640', 'Windows Server 2012 R2', '6.3', 1, 'SRV', 'high', 'restricted'],
            ['vm', 'VMware', 'Virtual Machine', 'Ubuntu Server', '24.04 LTS', 2, 'VM', 'high', 'confidential'],
            ['vm', 'Amazon', 'EC2 m6i.large', 'Amazon Linux', '2023', 2, 'AWS', 'high', 'confidential'],
            ['mobile', 'Apple', 'iPhone 15', 'iOS', '17.6', 2, 'MOB', 'low', 'internal'],
            ['mobile', 'Samsung', 'Galaxy S23', 'Android', '14', 1, 'MOB', 'low', 'internal'],
            ['tablet', 'Apple', 'iPad Air (5th gen)', 'iPadOS', '17.6', 2, 'TAB', 'low', 'internal'],
            ['network', 'Cisco', 'Catalyst 9300-48P', 'IOS XE', '17.9.4', 1, 'SW', 'critical', 'internal'],
            ['network', 'Ubiquiti', 'UniFi U6 Pro AP', 'UniFi OS', '6.6.55', 1, 'AP', 'medium', 'internal'],
            ['firewall', 'Fortinet', 'FortiGate 100F', 'FortiOS', '7.2.8', 1, 'FW', 'critical', 'restricted'],
            ['printer', 'HP', 'LaserJet Enterprise M507', 'FutureSmart', '5.6', 1, 'PRN', 'low', 'internal'],
            ['iot', 'Axis', 'M3106-L Mk II camera', 'AXIS OS', '10.12', 1, 'CAM', 'low', 'internal'],
        ];

        $this->as('jpatel');
        $devices = [];
        $n = 0;
        foreach ($templates as [$type, $make, $model, $os, $ver, $count, $prefix, $crit, $class]) {
            for ($i = 0; $i < $count; $i++) {
                $n++;
                $loc = match ($type) {
                    'server', 'network', 'firewall' => mt_rand(0, 3) ? 'Data Centre - Slough' : 'London HQ',
                    'vm' => $make === 'Amazon' ? 'Cloud - AWS eu-west-2' : 'Data Centre - Slough',
                    'printer', 'iot', 'desktop' => mt_rand(0, 1) ? 'London HQ' : 'Manchester Office',
                    default => ['London HQ', 'Manchester Office', 'Remote / Home'][mt_rand(0, 2)],
                };
                $site = match ($loc) { 'Manchester Office' => 'MAN', 'Data Centre - Slough' => 'DC1', 'Cloud - AWS eu-west-2' => 'AWS', default => 'LON' };
                $subnet = match ($site) { 'MAN' => '10.20', 'DC1' => '10.50', 'AWS' => '172.31', default => '10.10' };
                $personal = in_array($type, ['laptop', 'desktop', 'mobile', 'tablet'], true);
                $dept = $personal ? $depts[mt_rand(0, count($depts) - 1)] : 'IT';

                // Device checks: most pass, ~30% with at least one gap
                $gap = mt_rand(1, 100) <= 30;
                $controls = ['disk_encrypted' => 1, 'av_edr_installed' => 1, 'mdm_enrolled' => 1, 'firewall_enabled' => 1];
                if ($gap) {
                    $controls[array_rand($controls)] = 0;
                }
                if ($os === 'Windows Server 2012 R2' || $type === 'iot' || $type === 'printer') {
                    $controls['av_edr_installed'] = 0;
                    $controls['mdm_enrolled'] = 0;
                }
                $patchedDaysAgo = $gap && mt_rand(0, 1) ? mt_rand(35, 140) : mt_rand(1, 26);
                if ($os === 'Windows Server 2012 R2') {
                    $patchedDaysAgo = 410;
                }

                $purchaseDaysAgo = mt_rand(120, 1700);
                $warrantyYears = in_array($type, ['server', 'network', 'firewall'], true) ? 5 : 3;
                $warranty = date('Y-m-d', strtotime("-{$purchaseDaysAgo} days +{$warrantyYears} years"));
                if ($n % 9 === 0) {
                    $warranty = self::d(mt_rand(10, 55)); // expiring soon
                }
                $eol = date('Y-m-d', strtotime("-{$purchaseDaysAgo} days +" . ($warrantyYears + 2) . ' years'));
                if ($os === 'Windows Server 2012 R2' || $os === 'Windows 10 Enterprise') {
                    $eol = $os === 'Windows Server 2012 R2' ? '2023-10-10' : '2025-10-14';
                }

                $status = 'active';
                if ($n === 7) $status = 'in_repair';
                if ($n === 12) $status = 'in_storage';
                if ($n === 15) $status = 'lost';
                if ($n === 33) $status = 'stolen';
                if ($n === 21) $status = 'retired';

                $hostname = sprintf('%s-%s-%03d', $prefix, $site, 100 + $n);
                $person = $personal ? $people[($n * 7) % count($people)] : null;
                // Phones and tablets have no managed hostname; every other one is personally owned
                $handheld = in_array($type, ['mobile', 'tablet'], true);
                $device = new Device([
                    'name' => $handheld ? strtok($person, ' ') . "'s " . $model : $hostname,
                    'hostname' => $handheld ? null : $hostname,
                    'device_type' => $type,
                    'ownership' => $handheld && $n % 2 ? Device::OWNERSHIP_BYOD : Device::OWNERSHIP_COMPANY,
                    'manufacturer' => $make,
                    'model' => $model,
                    'serial_number' => strtoupper(substr($make, 0, 2)) . sprintf('%08X', mt_rand(0x10000000, 0x7FFFFFFF)),
                    'os' => $os,
                    'os_version' => $ver,
                    'assigned_to' => $person,
                    'department_id' => $this->lookup(Lookup::TYPE_DEPARTMENT, $dept),
                    'location_id' => $this->lookup(Lookup::TYPE_LOCATION, $loc),
                    'ip_addresses' => in_array($type, ['mobile', 'tablet'], true) ? null : sprintf('%s.%d.%d', $subnet, mt_rand(1, 30), mt_rand(10, 250)),
                    'mac_address' => in_array($type, ['vm'], true) && $make === 'Amazon' ? null : implode(':', array_map(static fn() => sprintf('%02X', mt_rand(0, 255)), range(1, 6))),
                    'purchase_date' => self::d(-$purchaseDaysAgo),
                    'warranty_expiry' => $warranty,
                    'eol_date' => $eol,
                    'status' => $status,
                    'criticality' => $crit,
                    'data_classification' => $class,
                    'last_patched_at' => self::d(-$patchedDaysAgo),
                    'notes' => $os === 'Windows Server 2012 R2' ? 'Hosts legacy finance application (FinPro 7). Isolated VLAN. Migration tracked under DEC-0004.' : null,
                ]);
                $device->checks = [];
                foreach ($controls as $key => $passed) {
                    $check = DeviceChecks::find($checks[$key]);
                    if ($check && $check->appliesTo($type, $device->ownership)) {
                        $device->checks[$check->id] = $passed ? 'pass' : 'fail';
                    }
                }
                // Handhelds sync from Google Workspace, as from `make import-devices`; one in four has gone quiet
                if ($handheld && $person) {
                    $device->setAttributes([
                        'assigned_email' => strtolower(str_replace(' ', '.', $person)) . '@example.com',
                        'source' => Device::SOURCE_GOOGLE,
                        'source_id' => sprintf('%08X-%04X-4%03X-A%03X-%012X', mt_rand(), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFF), mt_rand(0, 0xFFF), mt_rand() * 4096),
                        'first_seen_at' => self::d(-min($purchaseDaysAgo, 360)) . ' 09:15:00',
                        'last_seen_at' => self::d($n % 4 ? -mt_rand(0, 3) : -mt_rand(45, 120)) . sprintf(' %02d:%02d:00', mt_rand(7, 22), mt_rand(0, 59)),
                    ], false);
                    if ($device->isByod()) {
                        $device->checks = []; // the export has no posture data: not assessed yet
                    }
                }
                if ($type === 'server' && $i === 0 && $make === 'Dell' && $model === 'PowerEdge R750') {
                    $device->ip_addresses .= ', 10.50.0.' . (10 + $n) . ', fd00:50::' . dechex(100 + $n);
                }
                $this->save($device);
                $this->backdate($device, self::d(-min($purchaseDaysAgo, 360)) . ' 09:00:00');
                $devices[$n] = $device;
            }
        }
        return $devices;
    }

    // ---- Decisions ----------------------------------------------------------

    private function seedDecisions(): array
    {
        $c = fn(string $name) => $this->lookup(Lookup::TYPE_DECISION_CATEGORY, $name);
        // [title, category, daysAgo, status, makers, stakeholders, context, options, decision, rationale, impact, reviewInDays]
        $rows = [
            ['Adopt Microsoft Defender for Endpoint as the standard EDR', 'Tooling', 330, 'implemented', 'CISO, IT Director', 'IT Operations, Finance (budget)',
                'Legacy antivirus provided no behavioural detection and no central visibility; two malware incidents were only detected by users.',
                "1. Renew legacy AV\n2. CrowdStrike Falcon\n3. Microsoft Defender for Endpoint P2 (already licensed via M365 E5)",
                'Deploy Defender for Endpoint P2 to all Windows, macOS and server endpoints.',
                'Already licensed, integrates with Intune and Sentinel, strong detection scores, lowest total cost.',
                'Legacy AV contract not renewed (saving ~£18k/yr). Requires onboarding of 2012 R2 servers via legacy agent.', 35],
            ['Enforce phishing-resistant MFA for all staff', 'Policy', 250, 'approved', 'CISO', 'HR, Service Desk, Executive team',
                'Credential phishing remains the top initial access vector; SMS-based MFA has been bypassed in two incidents.',
                "1. Keep SMS MFA\n2. Authenticator app number matching\n3. FIDO2 security keys / passkeys",
                'Roll out FIDO2 keys and passkeys to all staff; disable SMS and voice MFA methods by the end of Q2.',
                'Phishing-resistant MFA defeats adversary-in-the-middle kits used in recent campaigns.',
                'Hardware cost ~£25 per user; service desk enrolment effort; small number of shared accounts to redesign.', 120],
            ['Accept residual risk of tailgating at London HQ reception', 'Risk Acceptance', 200, 'approved', 'COO', 'Facilities, Security',
                'Turnstiles quoted at £45k; building landlord will not permit installation before lease renewal.',
                "1. Install turnstiles\n2. Add security guard\n3. Accept risk with awareness campaign",
                'Accept the residual risk until lease renewal; run a tailgating awareness campaign and badge-checking spot checks.',
                'Cost of controls disproportionate to impact given CCTV and locked server room.',
                'Revisit at lease renewal (12 months).', -15],
            ['Migrate FinPro 7 off Windows Server 2012 R2', 'Architecture', 180, 'approved', 'IT Director, Finance Director', 'Finance users, FinPro vendor',
                'Windows Server 2012 R2 is out of support and cannot be patched; it hosts the legacy finance application.',
                "1. Extended Security Updates (ESU)\n2. Rehost on Server 2022\n3. Replace with SaaS finance platform",
                'Rehost FinPro 7 on Windows Server 2022 now, and evaluate SaaS replacement in next budget cycle.',
                'Fastest route to a supported platform; vendor confirmed compatibility.',
                'Two weekend maintenance windows; temporary dual running.', 60],
            ['Purchase cyber insurance (£2m cover)', 'Vendor / Procurement', 300, 'implemented', 'CFO', 'Legal, Broker',
                'Board asked for risk transfer for ransomware and third-party data breach scenarios.',
                "1. No insurance\n2. £1m cover\n3. £2m cover incl. incident response retainer",
                'Purchase £2m cyber policy including breach coach and IR retainer.',
                'Covers worst-case ransomware recovery estimate; IR retainer reduces time to engage specialists.',
                'Annual premium £14k; insurer requires MFA and offline backups as conditions.', 90],
            ['Retire on-premises Exchange in favour of Exchange Online', 'Architecture', 280, 'implemented', 'IT Director', 'All departments',
                'On-prem Exchange 2016 has been the target of multiple critical CVEs and requires frequent emergency patching.',
                "1. Upgrade to Exchange SE\n2. Move fully to Exchange Online",
                'Complete migration to Exchange Online and decommission hybrid server.',
                'Removes a high-risk internet-facing service and patching burden.',
                'Hybrid management tooling retained for attribute management only.', 200],
            ['Temporary exception: legacy CCTV firmware', 'Exception', 240, 'superseded', 'Facilities Manager', 'IT Security',
                'CCTV cameras cannot be upgraded until the NVR is replaced.',
                "1. Replace cameras now\n2. Grant 6-month exception",
                'Grant a 6-month exception while NVR replacement is procured.',
                'Replacement budget not available until next quarter.',
                'Cameras remain on corporate VLAN in the interim.', null],
            ['Introduce 72-hour patch SLA for internet-facing systems', 'Policy', 20, 'proposed', 'CISO', 'IT Operations, Change Advisory Board',
                'VPN appliance exploitation attempt (INC-0008) showed patch lag of 19 days on edge devices.',
                "1. Keep monthly patch cycle\n2. 7-day SLA\n3. 72-hour SLA for critical/edge",
                'Critical vulnerabilities on internet-facing systems must be patched or mitigated within 72 hours.',
                'Exploitation of edge devices now typically occurs within days of disclosure.',
                'Out-of-hours change process needed; may require additional on-call budget.', 180],
            ['Select Cloudflare for DDoS protection of customer portal', 'Vendor / Procurement', 12, 'proposed', 'IT Director', 'Digital team, Procurement',
                'Customer portal suffered a volumetric DDoS (INC-0005) causing 3 hours of downtime.',
                "1. ISP scrubbing service\n2. AWS Shield Advanced\n3. Cloudflare Pro + WAF",
                'Front the customer portal with Cloudflare Pro including WAF and rate limiting.',
                'Best cost/benefit; also provides WAF and bot management.',
                'DNS change required; £2.4k/yr.', 180],
            ['Mandate BitLocker on all Windows laptops', 'Policy', 260, 'implemented', 'CISO', 'Service Desk, HR',
                'A lost laptop (INC-0003) was encrypted, but audit found 6% of laptops without BitLocker.',
                "1. Recommend encryption\n2. Enforce via Intune compliance policy",
                'Enforce BitLocker with TPM+PIN via Intune; non-compliant devices blocked from M365.',
                'Encryption makes lost devices a non-reportable event in most cases.',
                'Small number of legacy devices to replace.', 100],
            ['Segment IoT devices and printers into a dedicated VLAN', 'Architecture', 90, 'approved', 'Network Manager, CISO', 'Facilities',
                'Printers and CCTV share the corporate VLAN and have default credentials; supersedes the CCTV firmware exception.',
                "1. Leave as is\n2. Dedicated IoT VLAN with ACLs",
                'Create IoT VLAN with deny-by-default ACLs; only print server and NVR may communicate with devices.',
                'Prevents lateral movement from weakly secured devices.',
                'Firewall rule changes; printer re-addressing.', 150],
            ['Increase security log retention to 12 months', 'Policy', 8, 'proposed', 'CISO', 'IT Operations, Legal, DPO',
                'Current 30-day retention prevented full scoping of INC-0013.',
                "1. Keep 30 days\n2. 90 days hot\n3. 90 days hot + 12 months archive",
                'Retain 90 days searchable plus 12 months archived logs for security-relevant sources.',
                'Typical dwell times exceed 30 days; aligns with insurer expectations.',
                'Additional storage cost ~£6k/yr.', 180],
            ['Use of personal Dropbox accounts for client file transfers', 'Exception', 150, 'rejected', 'CISO, DPO', 'Sales',
                'Sales requested an exception to share large files with clients via personal Dropbox.',
                "1. Approve exception\n2. Use corporate SharePoint external sharing",
                'Request rejected. Use SharePoint external sharing with expiring links instead.',
                'Personal accounts are outside company control and retention; data protection risk.',
                'Sales trained on secure external sharing.', null],
            ['Outsource 24x7 SOC monitoring to an MSSP', 'Vendor / Procurement', 4, 'proposed', 'CISO, CFO', 'IT Operations',
                'Alerts outside business hours are not triaged until the next working day.',
                "1. In-house on-call rota\n2. MSSP 24x7 MDR service",
                'Contract an MSSP for 24x7 managed detection and response.',
                'Out-of-hours coverage at lower cost than three additional FTEs.',
                'Requires onboarding of log sources and runbooks.', 120],
            ['Quarterly user access reviews for the finance ERP', 'Process', 60, 'approved', 'Finance Director', 'Internal Audit, IT',
                'Audit found leavers and role changers retaining finance system access.',
                "1. Annual review\n2. Quarterly review with manager attestation",
                'Quarterly access reviews with line manager attestation, tracked in the risk register.',
                'Reduces fraud and error risk; satisfies auditors.',
                'Finance managers to spend ~2 hours per quarter.', 30],
        ];

        $decisions = [];
        foreach ($rows as $i => [$title, $cat, $daysAgo, $status, $makers, $stake, $context, $options, $text, $rationale, $impact, $review]) {
            $this->as($i % 3 === 0 ? 'admin' : 'analyst');
            $d = new Decision([
                'title' => $title,
                'category_id' => $c($cat),
                'decision_date' => self::d(-$daysAgo),
                'status' => $status === 'superseded' ? 'approved' : $status,
                'decision_makers' => $makers,
                'stakeholders' => $stake,
                'context' => $context,
                'options_considered' => $options,
                'decision_text' => $text,
                'rationale' => $rationale,
                'impact' => $impact,
                'review_date' => $review === null ? null : self::d($review),
                'mitigationIds' => $this->attackIds(AttackMitigation::class, self::ATTACK_DECISIONS[$i + 1] ?? []),
            ]);
            $this->save($d);
            $this->backdate($d, self::d(-$daysAgo) . ' 14:00:00');
            $decisions[$i + 1] = $d;
        }
        // DEC-0007 superseded by DEC-0011
        $this->as('admin');
        $decisions[7]->status = Decision::STATUS_SUPERSEDED;
        $decisions[7]->superseded_by_id = $decisions[11]->id;
        $this->save($decisions[7]);
        return $decisions;
    }

    // ---- Incidents --------------------------------------------------------------

    private function seedIncidents(array $devices): array
    {
        $cat = fn(string $name) => $this->lookup(Lookup::TYPE_INCIDENT_CATEGORY, $name);
        $flow = ['new', 'triage', 'investigating', 'contained', 'eradicated', 'recovered', 'closed'];

        // [title, category, severity, final status, detectedHoursAgo, ttdHours, containHours, closeHours, pii, regulator, notifyHours|null, assignee, reportedBy, devices[], description, containment, recovery, root cause, lessons]
        $rows = [
            ['CEO impersonation phishing campaign', 'Phishing', 'medium', 'closed', 24 * 335, 2, 3, 48, false, false, null, 'analyst', 'Service Desk',
                [], 'Twenty-three staff received emails impersonating the CEO requesting gift card purchases.',
                'Sender domain blocked; messages purged from mailboxes via Content Search.', 'Awareness bulletin sent to all staff.',
                'Look-alike domain registered by attacker; no DMARC enforcement on look-alikes.', 'Add look-alike domain monitoring; banner for external senders.'],
            ['Ransomware detected on finance workstation', 'Malware', 'high', 'closed', 24 * 305, 1, 2, 96, false, false, null, 'jpatel', 'EDR alert',
                [17], 'EDR flagged mass file renames on a finance desktop. Device isolated before spread.',
                'Device isolated via EDR; user account disabled; SMB shares checked for encryption.', 'Device re-imaged; 14 files restored from backup.',
                'User opened malicious ISO attachment; macro-less loader bypassed email filter.', 'Block ISO/IMG attachments at the gateway.'],
            ['Laptop lost on commuter train', 'Lost/Stolen Device', 'high', 'closed', 24 * 280, 10, 1, 72, true, false, null, 'analyst', 'Olivia Bennett',
                [15], 'Staff member reported company laptop left on a train. Device holds HR case notes.',
                'Device remotely locked and wipe issued via Intune.', 'Replacement issued; credentials reset.',
                'Human error; device confirmed BitLocker-encrypted so regulator notification not required.', 'Reinforce travel security guidance.'],
            ['Former employee accessed shared HR mailbox', 'Unauthorised Access', 'high', 'closed', 24 * 270, 72, 2, 240, true, true, 40, 'jpatel', 'HR Manager',
                [], 'A leaver retained delegated access to the HR shared mailbox for 3 weeks after departure and accessed 12 messages.',
                'Delegation removed; account disabled; sign-in logs reviewed.', 'Leaver process updated to include mailbox delegations.',
                'Leaver checklist did not cover shared mailbox permissions.', 'Automate removal of delegations in the leaver workflow.'],
            ['DDoS attack against customer portal', 'Denial of Service', 'medium', 'closed', 24 * 245, 0.2, 3, 30, false, false, null, 'jpatel', 'Monitoring',
                [], 'Volumetric attack (~4 Gbps) caused portal downtime for 3 hours.',
                'ISP null-routed attacking ranges; temporary geo-blocking.', 'Service restored; capacity review completed.',
                'No upstream DDoS protection.', 'Evaluate DDoS protection service (see DEC-0009).'],
            ['Malware on reception PC via USB stick', 'Malware', 'low', 'closed', 24 * 215, 4, 1, 26, false, false, null, 'analyst', 'Reception',
                [18], 'Visitor USB stick used to print documents contained a worm; blocked by EDR.',
                'USB removed, device scanned.', 'No further action required.',
                'USB storage not restricted on reception PC.', 'Block removable storage on shared devices.'],
            ['Payroll file emailed to wrong recipient', 'Data Breach', 'medium', 'closed', 24 * 185, 20, 6, 168, true, true, 90, 'analyst', 'Payroll Officer',
                [], 'Monthly payroll summary (names, salaries, NI numbers for 48 staff) was emailed to an external contact with a similar name.',
                'Recipient contacted and confirmed deletion in writing.', 'Affected staff informed.',
                'Email autocomplete selected external address; no DLP warning.', 'Enable DLP policy tips for NI numbers; disable autocomplete for external domains.'],
            ['Exploitation attempt against VPN appliance (CVE)', 'Vulnerability Exploitation', 'critical', 'closed', 24 * 180, 6, 4, 120, false, false, null, 'jpatel', 'Threat intel feed',
                [38], 'Threat intelligence reported active exploitation of a FortiOS SSL-VPN vulnerability; logs show exploitation attempts from 3 IPs.',
                'SSL-VPN disabled temporarily; attacker IPs blocked; integrity check run.', 'Firmware upgraded; VPN re-enabled with MFA enforcement.',
                'Patch not applied for 19 days after vendor release.', 'Introduce 72-hour patch SLA for internet-facing systems (DEC-0008).'],
            ['Invoice fraud attempt via compromised supplier mailbox', 'Phishing', 'high', 'closed', 24 * 150, 30, 4, 72, false, false, null, 'analyst', 'Accounts Payable',
                [], 'Email from genuine supplier mailbox requested change of bank details for a £38k invoice.',
                'Payment halted; supplier contacted by phone.', 'Supplier confirmed their mailbox compromise.',
                'Supplier account compromise; our call-back control worked.', 'Make call-back verification mandatory for all bank detail changes.'],
            ['Company mobile phone stolen', 'Lost/Stolen Device', 'medium', 'closed', 24 * 140, 3, 1, 24, false, false, null, 'jpatel', 'Liam Murphy',
                [33], 'Phone stolen from a café. Device enrolled in MDM with passcode.',
                'Remote wipe issued and confirmed.', 'Replacement device issued.',
                'Theft; controls effective.', 'None.'],
            ['Unauthorised remote-access software installed', 'Insider/Policy Violation', 'low', 'closed', 24 * 110, 48, 2, 96, false, false, null, 'analyst', 'EDR alert',
                [3], 'A developer installed an unapproved remote-access tool to work from home.',
                'Software removed; user reminded of policy.', 'Application allow-listing reviewed.',
                'Users have local admin rights on engineering laptops.', 'Remove local admin; provide approved remote access.'],
            ['Credential stuffing against customer portal', 'Unauthorised Access', 'medium', 'recovered', 24 * 85, 12, 5, null, true, false, null, 'jpatel', 'Monitoring',
                [], '1,900 login attempts from botnet; 11 customer accounts successfully accessed.',
                'Affected accounts locked; rate limiting enabled.', 'Customers forced to reset passwords.',
                null, null],
            ['Publicly accessible S3 bucket with customer exports', 'Data Breach', 'critical', 'closed', 24 * 80, 300, 1, 200, true, true, 60, 'jpatel', 'External researcher',
                [29], 'Security researcher reported a public bucket containing CSV exports with names and email addresses of 3,200 customers.',
                'Public access blocked immediately; access logs preserved.', 'Bucket policy fixed; account-wide Block Public Access enabled.',
                'Bucket created by test script with public ACL; no guardrail.', 'Enable AWS Config conformance pack; restrict who can change bucket ACLs.'],
            ['Malicious OAuth application consent', 'Phishing', 'high', 'eradicated', 24 * 50, 18, 3, null, false, false, null, 'analyst', 'Microsoft Defender',
                [], 'Three users consented to a malicious app requesting Mail.Read; the app read mail for 18 hours.',
                'App disabled and consent revoked; tokens invalidated.', 'User consent to unverified apps disabled tenant-wide.',
                null, null],
            ['Suspicious PowerShell activity on file server', 'Malware', 'critical', 'contained', 24 * 26, 2, 5, null, false, false, null, 'jpatel', 'EDR alert',
                [22], 'Encoded PowerShell launched from a service account on the file server, reaching out to an unknown host.',
                'Server isolated; service account disabled; outbound IP blocked.', null, null, null],
            ['QR-code phishing (quishing) campaign', 'Phishing', 'medium', 'investigating', 24 * 18, 6, null, null, false, false, null, 'analyst', 'Service Desk',
                [], 'Emails with QR codes leading to fake Microsoft login pages received by ~60 staff.', null, null, null, null],
            ['USB stick with HR documents lost', 'Lost/Stolen Device', 'high', 'triage', 24 * 5, 30, null, null, true, true, null, 'analyst', 'HR Advisor',
                [], 'An unencrypted USB stick containing disciplinary letters for 7 employees was lost in transit between offices.', null, null, null, null],
            ['Exposed RDP service found by external scan', 'Vulnerability Exploitation', 'high', 'investigating', 24 * 4, 72, null, null, false, false, null, 'jpatel', 'Attack surface monitoring',
                [26], 'External attack surface scan found RDP exposed on the legacy finance server.', null, null, null, null],
            ['Customer records emailed to wrong client', 'Data Breach', 'high', 'new', 20, 2, null, null, true, true, null, 'analyst', 'Account Manager',
                [], 'A spreadsheet with contact details of another client\'s 140 customers was attached to an email in error.', null, null, null, null],
            ['Brute-force attempts on VPN accounts', 'Unauthorised Access', 'medium', 'new', 26, 1, null, null, false, false, null, 'jpatel', 'SIEM alert',
                [38], 'Spike in failed VPN authentications (4,000 in 1 hour) against 120 usernames.', null, null, null, null],
        ];

        $incidents = [];
        foreach ($rows as $idx => [$title, $category, $sev, $final, $detAgo, $ttd, $contain, $close, $pii, $reg, $notifyH, $assignee, $reporter, $devIdx, $desc, $containTxt, $recoveryTxt, $root, $lessons]) {
            $this->as($assignee);
            $detected = self::dt(-$detAgo);
            $occurred = self::dt(-$detAgo - $ttd);
            $finalPos = array_search($final, $flow, true);
            $containedAt = $contain !== null && $finalPos >= 3 ? self::dt(-$detAgo + $contain) : null;
            $closedAt = $close !== null && $final === 'closed' ? self::dt(-$detAgo + $close) : null;

            $inc = new Incident([
                'title' => $title,
                'category_id' => $cat($category),
                'severity' => $sev,
                'status' => $final,
                'occurred_at' => $occurred,
                'detected_at' => $detected,
                'reported_at' => self::dt(-$detAgo + 0.5),
                'reported_by' => $reporter,
                'assigned_to_id' => $this->users[$assignee],
                'personal_data_involved' => $pii,
                'regulator_notification_required' => $reg,
                'regulator_notified_at' => $notifyH !== null ? self::dt(-$detAgo + $notifyH) : null,
                'regulator_reference' => $notifyH !== null ? 'ICO-' . (1000000 + mt_rand(0, 899999)) : null,
                'subjects_notified_at' => $notifyH !== null ? self::dt(-$detAgo + $notifyH + 24) : null,
                'description' => $desc,
                'impact_confidentiality' => str_contains(self::CIA_INCIDENTS[$idx + 1] ?? '', 'C'),
                'impact_integrity' => str_contains(self::CIA_INCIDENTS[$idx + 1] ?? '', 'I'),
                'impact_availability' => str_contains(self::CIA_INCIDENTS[$idx + 1] ?? '', 'A'),
                'containment_actions' => $containTxt,
                'recovery_actions' => $recoveryTxt,
                'root_cause' => $root,
                'lessons_learned' => $lessons,
                'contained_at' => $containedAt,
                'closed_at' => $closedAt,
                'deviceIds' => array_values(array_filter(array_map(static fn($n) => isset($devices[$n]) ? $devices[$n]->id : null, $devIdx))),
                'techniqueIds' => $this->attackIds(AttackTechnique::class, self::ATTACK_INCIDENTS[$idx + 1] ?? []),
            ]);
            $inc->autoTimeline = false;
            $this->save($inc);
            $this->backdate($inc, $detected);

            // Timeline: walk the lifecycle up to the final status
            $steps = $finalPos;
            $start = strtotime($detected);
            $end = $closedAt !== null ? strtotime($closedAt)
                : min(time(), max($start + 3600 * max(2, $detAgo * 0.6), $containedAt !== null ? strtotime($containedAt) + 3600 : 0));
            // Detection, containment and closure are fixed points; the other status changes fall evenly between them
            $anchors = [0 => $start];
            if ($containedAt !== null) {
                $anchors[array_search('contained', $flow, true)] = strtotime($containedAt);
            }
            $anchors[$closedAt !== null ? $steps : $steps + 1] = $end;
            IncidentTimeline::add($inc->id, IncidentTimeline::TYPE_STATUS, "Incident logged with status New, severity {$inc->getSeverityLabel()}.", $detected);
            IncidentTimeline::add($inc->id, IncidentTimeline::TYPE_NOTE, "Initial report received from {$reporter}.", date('Y-m-d H:i:s', $start + 900));
            for ($s = 1; $s <= $steps; $s++) {
                $when = self::interpolate($anchors, $s);
                $from = Incident::statusOptions()[$flow[$s - 1]];
                $to = Incident::statusOptions()[$flow[$s]];
                IncidentTimeline::add($inc->id, IncidentTimeline::TYPE_STATUS, "Status changed from {$from} to {$to}.", date('Y-m-d H:i:s', min($when, time())));
                if ($flow[$s] === 'contained' && $containTxt) {
                    IncidentTimeline::add($inc->id, IncidentTimeline::TYPE_ACTION, $containTxt, date('Y-m-d H:i:s', min($when - 600, time())));
                }
                if ($flow[$s] === 'recovered' && $recoveryTxt) {
                    IncidentTimeline::add($inc->id, IncidentTimeline::TYPE_ACTION, $recoveryTxt, date('Y-m-d H:i:s', min($when - 600, time())));
                }
            }
            if ($notifyH !== null) {
                IncidentTimeline::add($inc->id, IncidentTimeline::TYPE_COMMUNICATION, "Breach reported to the ICO (ref {$inc->regulator_reference}).", $inc->regulator_notified_at);
            }
            $incidents[$idx + 1] = $inc;
        }
        return $incidents;
    }

    // ---- Threat intel ---------------------------------------------------------------

    /** Blocklist entries, analyst verdicts and notes (documentation ranges only). Returns the number of blocks. */
    private function seedIntel(array $incidents): int
    {
        $phishing = $incidents[1] ?? null;
        $ransomware = $incidents[2] ?? null;
        $ddos = $incidents[5] ?? null;
        if ($ddos) {
            IncidentTimeline::add($ddos->id, IncidentTimeline::TYPE_EVIDENCE, 'NetFlow: 71% of attack traffic from 198.51.100.0/24, top talker 198.51.100.23.', date('Y-m-d H:i:s', strtotime($ddos->detected_at) + 1800));
        }
        if ($ransomware) {
            IncidentTimeline::add($ransomware->id, IncidentTimeline::TYPE_EVIDENCE, 'EDR: loader beaconed to 192.0.2.44:443 every 60s before isolation.', date('Y-m-d H:i:s', strtotime($ransomware->detected_at) + 600));
        }

        // [value, severity, direction, reason, incident, expires (days, null = permanent), unblocked days ago, created days ago, user]
        $rows = [
            ['203.0.113.66', 'critical', 'both', 'Phishing kit host and sender infrastructure for the CEO impersonation campaign.', $phishing, null, null, 330, 'analyst'],
            ['192.0.2.44', 'critical', 'outbound', 'Ransomware loader C2 (beacon seen by EDR on the finance workstation).', $ransomware, null, null, 300, 'jpatel'],
            ['198.51.100.0/24', 'high', 'inbound', 'DDoS source range against the customer portal; most attack traffic came from this /24.', $ddos, 5, null, 240, 'jpatel'],
            ['203.0.113.128/25', 'medium', 'inbound', 'Credential-stuffing attempts on the VPN portal (fail2ban).', null, -3, null, 40, 'analyst'],
            ['203.0.113.200', 'low', 'inbound', 'SSH scanning seen on the bastion host.', null, null, 20, 60, 'analyst'],
            ['AS64511', 'high', 'both', 'Bulletproof hosting network behind repeated phishing infrastructure (demo ASN).', $phishing, null, null, 320, 'analyst'],
            ['AS64500', 'medium', 'inbound', 'Network with high abuse density in threat feeds; blocked at the edge for 30 days.', null, 30, null, 2, 'jpatel'],
        ];
        $count = 0;
        foreach ($rows as [$value, $severity, $direction, $reason, $incident, $expires, $unblocked, $createdAgo, $user]) {
            $this->as($user);
            $entry = new BlocklistEntry([
                'value' => $value, 'severity' => $severity, 'direction' => $direction, 'reason' => $reason,
                'incident_id' => $incident?->id,
                'expires_at' => $expires === null ? null : self::d($expires) . ' 18:00:00',
            ]);
            // Expired / back-dated entries would fail the "expiry in the future" rule
            $entry->validate();
            $entry->clearErrors('expires_at');
            if ($entry->hasErrors()) {
                throw new \RuntimeException('BlocklistEntry invalid: ' . json_encode($entry->getErrors()));
            }
            $entry->save(false);
            $this->backdate($entry, self::d(-$createdAgo) . ' 10:15:00');
            if ($unblocked !== null) {
                $entry->archive();
                BlocklistEntry::updateAll(['archived_at' => self::d(-$unblocked) . ' 09:00:00'], ['id' => $entry->id]);
            }
            $count++;
        }

        // Analyst verdicts and notes. [value, type, verdict, summary, notes [user, text, days ago]]
        $intel = [
            ['203.0.113.66', 'ip', 'malicious', 'Documentation (TEST-NET-3)', [
                ['analyst', 'Hosted the gift-card phishing landing page; also the SMTP source of the look-alike domain.', 330],
                ['jpatel', 'Still resolving for the look-alike domain a month later. Keep blocked.', 300],
            ]],
            ['192.0.2.44', 'ip', 'malicious', 'Documentation (TEST-NET-1)', [
                ['jpatel', 'C2 for the ISO loader in INC-0002. Beacon interval 60s, JA3 matches a known commodity loader.', 300],
            ]],
            ['198.51.100.23', 'ip', 'suspicious', 'Documentation (TEST-NET-2)', [
                ['jpatel', 'Top talker during the portal DDoS. Probably a compromised IoT device, not the operator.', 240],
            ]],
            ['203.0.113.200', 'ip', 'benign', 'Documentation (TEST-NET-3)', [
                ['analyst', 'Turned out to be our pen-test provider (engagement letter on file). Unblocked.', 20],
            ]],
            ['AS64511', 'asn', 'malicious', 'Documentation (RFC 5398)', [
                ['analyst', 'Several phishing domains over 2025-26 were hosted here; abuse reports go unanswered.', 320],
            ]],
        ];
        foreach ($intel as [$value, $type, $verdict, $summary, $notes]) {
            $this->as($notes[0][0]);
            $ind = new IntelIndicator(['type' => $type, 'value' => $value, 'summary' => $summary, 'last_rating' => 'special', 'last_score' => 0,
                'lookup_count' => count($notes) + 1, 'last_checked_at' => self::d(-$notes[count($notes) - 1][2]) . ' 11:00:00', 'last_checked_by' => $this->users[$notes[0][0]],
                'asn' => $type === 'asn' ? (int) substr($value, 2) : null]);
            $ind->save(false);
            $ind->setVerdict($verdict);
            IntelIndicator::updateAll(['verdict_at' => self::d(-$notes[0][2]) . ' 11:05:00', 'created_at' => self::d(-$notes[0][2]) . ' 10:55:00'], ['id' => $ind->id]);
            foreach ($notes as [$user, $text, $ago]) {
                $this->as($user);
                $note = $this->save(new IntelNote(['indicator_id' => $ind->id, 'body' => $text]));
                $this->backdate($note, self::d(-$ago) . ' 11:10:00');
            }
        }
        return $count;
    }

    // ---- Risks ------------------------------------------------------------------

    private function seedRisks(array $devices, array $incidents, array $decisions): array
    {
        $cat = fn(string $name) => $this->lookup(Lookup::TYPE_RISK_CATEGORY, $name);
        // [title, category, scope, threat, vulnerability, iL, iI, controls, treatment, plan, rL, rI, status, identifiedDaysAgo, targetInDays, reviewInDays, owner, devices[], incidents[], decisions[], acceptance]
        $rows = [
            ['Ransomware encrypts file servers and backups', 'Cyber Security', 'File servers, backup NAS', 'Organised ransomware groups', 'Flat network; backup repository reachable with domain credentials',
                4, 5, 'EDR on servers; nightly backups to NAS.', 'mitigate', 'Immutable off-site backups, network segmentation, tiered admin model.', 4, 5, 'in_treatment', 300, 30, 20, 'jpatel', [22, 23, 24], [2, 15], [1, 5], null],
            ['Credential phishing compromises Microsoft 365 accounts', 'Cyber Security', 'Microsoft 365 tenant', 'Phishing / AiTM kits', 'SMS MFA still allowed for 30% of users',
                4, 4, 'Email filtering; SMS-based MFA.', 'mitigate', 'Enforce phishing-resistant MFA; conditional access with compliant device requirement.', 3, 3, 'in_treatment', 250, -15, 40, 'analyst', [], [1, 14, 16], [2], null],
            ['Internet-facing VPN appliance exploited before patching', 'Cyber Security', 'Remote access VPN', 'Opportunistic and state actors exploiting edge CVEs', 'Patch lag on network appliances',
                3, 5, 'Vendor advisories monitored.', 'mitigate', '72-hour patch SLA for edge devices; evaluate ZTNA replacement.', 3, 4, 'assessed', 180, 45, 10, 'jpatel', [38], [8, 20], [8], null],
            ['Loss of laptop containing personal data', 'Information Governance', 'Staff laptops', 'Theft / loss', 'Some legacy laptops lacked full-disk encryption',
                3, 4, 'MDM enrolment; remote wipe.', 'mitigate', 'Enforce BitLocker via Intune compliance; block non-compliant devices.', 1, 3, 'in_treatment', 330, -5, -10, 'analyst', [1, 2, 15], [3], [10], null],
            ['Payroll provider suffers a data breach', 'Third Party / Supplier', 'Outsourced payroll data', 'Supplier compromise', 'Limited supplier security assurance',
                3, 4, 'Data processing agreement in place.', 'transfer', 'Cyber insurance; annual supplier security questionnaire.', 2, 4, 'accepted', 360, null, 60, 'admin', [], [], [5],
                'Risk transferred via cyber insurance; supplier holds ISO 27001. Residual risk within appetite.'],
            ['Insider exfiltrates customer data', 'People', 'CRM and customer exports', 'Malicious or disgruntled insider', 'No data loss prevention; broad export rights',
                2, 5, 'Role-based access in CRM.', 'mitigate', 'Deploy DLP for email and cloud storage; quarterly access reviews.', 2, 3, 'identified', 40, 90, 30, 'analyst', [], [], [], null],
            ['Data centre power failure causes extended outage', 'Business Continuity', 'Slough data centre', 'Utility power failure', 'Generator not load-tested',
                2, 4, 'UPS with 30 min runtime.', 'mitigate', 'Quarterly generator load tests; documented failover.', 1, 4, 'closed', 365, null, 120, 'jpatel', [22, 23, 36], [], [], null],
            ['Unsupported Windows Server 2012 R2 in production', 'Cyber Security', 'Legacy finance application server', 'Exploitation of unpatched vulnerabilities', 'No security updates since Oct 2023',
                4, 4, 'Isolated VLAN; restricted firewall rules.', 'mitigate', 'Rehost FinPro 7 on Windows Server 2022.', 4, 4, 'in_treatment', 270, -30, 14, 'jpatel', [26], [18], [4], null],
            ['Weak privileged access management', 'Cyber Security', 'Domain and cloud admin accounts', 'Credential theft / pass-the-hash', 'Shared admin accounts; standing privileges',
                3, 5, 'Separate admin accounts.', 'mitigate', 'Implement PAM with just-in-time elevation.', null, null, 'assessed', 90, 120, 45, 'jpatel', [], [], [], null],
            ['Breach notification deadlines missed', 'Compliance & Legal', 'Incident response process', 'Regulatory enforcement', 'No documented breach workflow',
                3, 4, 'DPO appointed.', 'mitigate', 'Incident runbook with 72h tracking in Greywarden.', 1, 3, 'closed', 300, null, 90, 'admin', [], [4, 7, 13], [], null],
            ['Shadow IT SaaS applications processing company data', 'Information Governance', 'Unsanctioned SaaS', 'Data leakage via unapproved apps', 'No CASB / SaaS discovery',
                4, 3, 'Acceptable use policy.', 'mitigate', 'CASB discovery; SaaS approval process.', 3, 2, 'identified', 25, 60, 30, 'analyst', [], [], [13], null],
            ['DDoS takes customer portal offline', 'Cyber Security', 'Customer portal', 'Hacktivists / extortion', 'No upstream DDoS mitigation',
                2, 3, 'Cloud autoscaling.', 'transfer', 'Front portal with Cloudflare (DEC-0009).', 1, 3, 'in_treatment', 240, 20, 30, 'jpatel', [29, 30], [5], [9], null],
            ['Tailgating into London HQ', 'Physical Security', 'London HQ', 'Unauthorised visitors', 'No turnstiles; single reception desk',
                3, 2, 'Reception desk; CCTV; locked server room.', 'accept', 'Awareness campaign; badge spot checks.', 3, 2, 'accepted', 210, null, -20, 'admin', [40], [], [3],
                'Cost of turnstiles (£45k) is disproportionate; landlord restrictions until lease renewal. Accepted by COO.'],
            ['Key-person dependency on sole firewall administrator', 'People', 'Network security operations', 'Staff turnover / absence', 'Undocumented firewall configuration',
                3, 3, 'Vendor support contract.', 'mitigate', 'Cross-train second engineer; document rule base.', 2, 2, 'in_treatment', 150, 45, 60, 'jpatel', [38], [], [], null],
            ['Developer workstation compromise leads to supply-chain attack', 'Cyber Security', 'Source repositories and CI/CD', 'Targeted attackers', 'Long-lived personal access tokens',
                2, 5, 'MFA on GitHub.', 'mitigate', 'Short-lived tokens; signed commits; protected branches.', 2, 5, 'assessed', 70, 60, 30, 'analyst', [3, 4, 5], [11], [], null],
            ['Backups not tested for restore', 'Business Continuity', 'Backup platform', 'Operational failure during recovery', 'No routine restore testing',
                3, 4, 'Backup job monitoring.', 'mitigate', 'Quarterly restore tests with documented RTO evidence.', 2, 3, 'in_treatment', 200, 15, -3, 'jpatel', [24, 25], [2], [], null],
            ['Misconfigured cloud storage exposes data', 'Cyber Security', 'AWS S3', 'Misconfiguration', 'No cloud security posture management',
                3, 4, 'Manual reviews.', 'mitigate', 'Account-wide Block Public Access; AWS Config rules.', 1, 4, 'closed', 80, null, 90, 'jpatel', [29, 30], [13], [12], null],
            ['Low security awareness among new starters', 'People', 'All staff', 'Social engineering', 'Training only delivered annually',
                4, 2, 'Annual e-learning.', 'mitigate', 'Onboarding security module; monthly phishing simulations.', 2, 2, 'in_treatment', 160, 30, 60, 'analyst', [], [1, 16], [], null],
            ['Printers and IoT devices on the corporate VLAN', 'Cyber Security', 'Printers, CCTV', 'Lateral movement', 'Default credentials; no segmentation',
                3, 3, 'None.', 'mitigate', 'Dedicated IoT VLAN (DEC-0011).', 2, 2, 'identified', 95, 45, 30, 'jpatel', [39, 40], [6], [7, 11], null],
            ['Business email compromise leads to invoice fraud', 'Operational', 'Accounts payable', 'Fraudsters / compromised suppliers', 'Bank detail changes accepted by email',
                3, 4, 'Bank detail change form.', 'mitigate', 'Mandatory call-back verification; payment approval thresholds.', 2, 3, 'in_treatment', 150, 10, 45, 'analyst', [], [9], [], null],
            ['Expired TLS certificates cause service outage', 'Operational', 'Public websites', 'Process failure', 'Manual certificate renewal',
                3, 2, 'Calendar reminders.', 'mitigate', 'ACME automation for all public certificates.', 1, 2, 'closed', 280, null, 180, 'jpatel', [], [], [], null],
            ['Unmanaged mobile devices access corporate email', 'Cyber Security', 'BYOD phones', 'Device loss / malware', 'No app protection policies',
                3, 3, 'Passcode policy.', 'mitigate', 'Intune app protection policies for Outlook and Teams.', 2, 2, 'assessed', 60, 30, 30, 'analyst', [31, 32, 33], [10], [], null],
            ['Excessive access rights in the finance ERP', 'Compliance & Legal', 'Finance ERP', 'Fraud and errors', 'No periodic access review',
                3, 3, 'Approval workflows.', 'mitigate', 'Quarterly access reviews (DEC-0015).', null, null, 'identified', 55, 90, -2, 'analyst', [26], [], [15], null],
            ['Vendor remote access is not monitored', 'Third Party / Supplier', 'MSP remote access tools', 'Supplier compromise', 'Always-on vendor accounts',
                3, 4, 'Dedicated VPN accounts.', 'mitigate', 'Session recording; time-bound access approvals.', 3, 4, 'in_treatment', 120, 25, 40, 'jpatel', [38], [], [14], null],
            ['Log retention insufficient for investigations', 'Compliance & Legal', 'SIEM and logging', 'Inability to scope incidents', '30-day log retention',
                3, 3, 'Basic logging.', 'accept', 'Budget request submitted (DEC-0012).', 3, 3, 'accepted', 30, null, 25, 'admin', [], [13], [12],
                'Accepted temporarily pending budget approval for extended retention in the next quarter.'],
        ];

        $risks = [];
        foreach ($rows as $idx => [$title, $category, $scope, $threat, $vuln, $iL, $iI, $controls, $treatment, $plan, $rL, $rI, $status, $identAgo, $target, $review, $owner, $devIdx, $incIdx, $decIdx, $accept]) {
            $this->as($owner === 'admin' ? 'admin' : $owner);
            $risk = new Risk([
                'title' => $title,
                'category_id' => $cat($category),
                'scope' => $scope,
                'threat_source' => $threat,
                'vulnerability' => $vuln,
                'description' => "{$title}. Affects: {$scope}.",
                'owner_id' => $this->users[$owner],
                'inherent_likelihood' => $iL,
                'inherent_impact' => $iI,
                'existing_controls' => $controls,
                'treatment' => $status === 'identified' ? null : $treatment,
                'treatment_plan' => $plan,
                'residual_likelihood' => $rL,
                'residual_impact' => $rI,
                'status' => $status,
                'date_identified' => self::d(-$identAgo),
                'target_treatment_date' => $target === null ? null : self::d($target),
                'next_review_date' => self::d($review),
                'acceptance_justification' => $accept,
                'accepted_by_id' => $accept ? $this->users['admin'] : null,
                'accepted_at' => $accept ? self::d(-max(1, (int) ($identAgo / 2))) : null,
                'deviceIds' => array_values(array_filter(array_map(static fn($n) => $devices[$n]->id ?? null, $devIdx))),
                'incidentIds' => array_values(array_filter(array_map(static fn($n) => $incidents[$n]->id ?? null, $incIdx))),
                'decisionIds' => array_values(array_filter(array_map(static fn($n) => $decisions[$n]->id ?? null, $decIdx))),
                'techniqueIds' => $this->attackIds(AttackTechnique::class, self::ATTACK_RISKS[$idx + 1] ?? []),
            ]);
            $this->save($risk);
            $this->backdate($risk, self::d(-$identAgo) . ' 10:30:00');
            $risks[$idx + 1] = $risk;
        }

        // A few follow-up edits so record histories show real diffs
        $this->as('analyst');
        $risks[2]->treatment_plan .= ' Pilot group of 40 users completed.';
        $risks[2]->residual_likelihood = 2;
        $this->save($risks[2]);
        $this->as('jpatel');
        $risks[1]->status = 'in_treatment';
        $risks[1]->target_treatment_date = self::d(30);
        $this->save($risks[1]);
        return $risks;
    }

    // ---- Controls ----------------------------------------------------------

    /**
     * Control Register: the device checks get owners, schedules and test history; the
     * other controls cover the risk register, ISO 27001 Annex A and Cyber Essentials.
     * Links are picked by record title so they survive changes to the seed order.
     *
     * @param array<string,int> $checks see seedDeviceChecks()
     * @return int number of controls
     */
    private function seedControls(array $checks, array $risks, array $decisions, array $incidents): int
    {
        $find = static fn(array $records, string $needle) => array_values(array_filter(
            $records, static fn($r) => stripos($r->title, $needle) !== false))[0]->id ?? null;
        $ids = static fn(array $records, array $needles) => array_values(array_filter(array_map(static fn($n) => $find($records, $n), $needles)));

        // [title, type, nature, status, owner, months between tests, implemented / target days, description,
        //  ISO / CE / SOC 2 / NIST CSF / CAF requirements, ATT&CK mitigations, risks, decisions, tests [days ago, result, method]]
        $rows = [
            ['Phishing-resistant MFA (FIDO2 / passkeys) for all staff', 'preventive', 'technical', 'implementing', 'jpatel', null, 45,
                'Replaces SMS and app-push MFA with FIDO2 security keys and passkeys, which adversary-in-the-middle phishing kits cannot relay.',
                ['A.5.17', 'A.8.5', 'CE-UA-4', 'CE-UA-7', 'CC6.1', 'PR.AA-03', 'B2.a'], ['M1032'], ['email compromise', 'privileged access'], ['phishing-resistant MFA'], []],
            ['Security awareness training and phishing simulation', 'preventive', 'administrative', 'implemented', 'analyst', 3, -300,
                'Onboarding security module, annual refresher and monthly phishing simulations with targeted follow-up training.',
                ['A.6.3', 'CC1.4', 'CC2.2', 'PR.AT-01', 'B6.b'], ['M1017'], ['security awareness', 'email compromise'], [],
                [[200, 'effective', 'Phishing simulation: 4% click rate'], [110, 'partial', 'Phishing simulation: 11% click rate (QR-code lure)'], [20, 'effective', 'Phishing simulation: 3% click rate']]],
            ['Immutable offline backups with quarterly restore tests', 'corrective', 'technical', 'implemented', 'jpatel', 3, -500,
                'Nightly backups to immutable object storage plus a weekly offline copy; a sample restore is tested each quarter.',
                ['A.8.13', 'A.5.30', 'A1.2', 'A1.3', 'PR.DS-11', 'RC.RP-03', 'B5.c'], ['M1053'], ['Backups not tested', 'power failure'], [],
                [[120, 'effective', 'File server restore test'], [25, 'ineffective', 'ERP restore test: RTO missed by 9 hours']]],
            ['Privileged access management and separate admin accounts', 'preventive', 'technical', 'implemented', 'jpatel', 6, -260,
                'Admins use separate accounts with just-in-time elevation; admin accounts cannot read email or browse the web.',
                ['A.8.2', 'A.8.18', 'CE-UA-5', 'CE-UA-6', 'CC6.3', 'PR.AA-05', 'B2.c'], ['M1026'], ['privileged access'], [],
                [[150, 'effective', 'Review of admin group membership']]],
            ['Joiner, mover and leaver access process', 'preventive', 'administrative', 'implemented', 'analyst', 3, -700,
                'HR triggers account creation and removal; managers approve access; leavers are disabled on their last day.',
                ['A.5.16', 'A.5.18', 'A.6.5', 'CE-UA-1', 'CE-UA-2', 'CE-UA-3', 'CC6.2', 'CC6.3', 'PR.AA-01', 'PR.AA-05', 'B2.d'], ['M1018'], ['Excessive access rights'], ['access reviews'],
                [[40, 'partial', 'Sample of 15 leavers: 2 accounts disabled late']]],
            ['Perimeter firewall rule review', 'preventive', 'technical', 'implemented', 'jpatel', 6, -900,
                'Inbound rules are approved, documented and reviewed every six months; the admin interface is reachable from the management VLAN only.',
                ['A.8.20', 'A.8.22', 'CE-FW-2', 'CE-FW-3', 'CE-FW-4', 'CE-FW-5', 'CE-FW-6', 'CC6.6', 'PR.IR-01', 'B4.b'], ['M1037', 'M1035'], ['firewall administrator'], [],
                [[95, 'effective', 'Rule base review, 4 stale rules removed']]],
            ['Email filtering and attachment sandboxing', 'detective', 'technical', 'implemented', 'jpatel', 12, -800,
                'Inbound mail is scanned and detonated in a sandbox; links are rewritten and checked at click time.',
                ['A.8.7', 'A.8.23', 'CE-MP-2', 'CC6.8', 'DE.CM-01', 'C1.c'], ['M1049', 'M1021', 'M1031'], ['email compromise', 'security awareness'], [],
                [[230, 'effective', 'Vendor configuration review']]],
            ['Vulnerability scanning of internet-facing systems', 'detective', 'technical', 'implemented', 'jpatel', 1, -400,
                'Weekly authenticated scans of the external estate; critical findings are ticketed against the patch SLA.',
                ['A.8.8', 'CE-SU-1', 'CE-SU-2', 'CC7.1', 'ID.RA-01', 'B4.d'], ['M1016'], ['Windows Server 2012', 'Expired TLS'], ['patch SLA'],
                [[75, 'effective', 'Scan coverage check']]],
            ['Supplier security assessments', 'preventive', 'administrative', 'planned', 'analyst', null, -10,
                'Security questionnaire and evidence review before onboarding a supplier with access to company data, repeated annually.',
                ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'CC9.2', 'GV.SC-06', 'GV.SC-07', 'A4.a'], [], ['Payroll provider', 'Vendor remote access'], [], []],
            ['Incident response plan and annual tabletop exercise', 'corrective', 'administrative', 'implemented', 'analyst', 12, -330,
                'Documented runbooks with 72-hour regulator tracking in Greywarden; exercised once a year with the leadership team.',
                ['A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28', 'A.6.8', 'CC7.3', 'CC7.4', 'CC7.5', 'RS.MA-01', 'ID.IM-02', 'D1.a', 'D1.c'], [], ['Breach notification'], [],
                [[300, 'effective', 'Ransomware tabletop exercise']]],
            ['Centralised logging and SIEM alerting', 'detective', 'technical', 'implementing', 'jpatel', null, 60,
                'Endpoint, identity and firewall logs feed the SIEM with 12-month retention and tuned alert rules.',
                ['A.8.15', 'A.8.16', 'A.8.17', 'CC7.2', 'PR.PS-04', 'DE.CM-09', 'C1.a', 'C1.b'], ['M1047'], ['Log retention'], ['log retention', 'SOC monitoring'], []],
            ['Cloud storage guardrails (Block Public Access, AWS Config)', 'preventive', 'technical', 'implemented', 'jpatel', 6, -120,
                'Account-wide S3 Block Public Access and AWS Config rules that alert on public or unencrypted buckets.',
                ['A.5.23', 'A.8.9', 'CE-SC-3', 'PR.PS-01', 'B4.b'], ['M1022'], ['cloud storage'], [], []],
            ['Password-only VPN access', 'preventive', 'technical', 'retired', 'jpatel', null, -1200,
                'Legacy SSL VPN with password-only authentication. Retired in favour of MFA-protected remote access.',
                ['A.8.5'], [], ['Vendor remote access'], [], []],
        ];

        $count = 0;
        foreach ($rows as [$title, $type, $nature, $status, $owner, $months, $days, $description, $requirements, $mitigations, $riskNeedles, $decisionNeedles, $tests]) {
            $this->as($owner);
            $control = $this->save(new Control([
                'title' => $title,
                'description' => $description,
                'control_type' => $type,
                'nature' => $nature,
                'status' => $status,
                'owner_id' => $this->users[$owner],
                'test_frequency_months' => $months,
                'implemented_on' => $status === Control::STATUS_PLANNED || $days > 0 ? null : self::d($days),
                'target_date' => in_array($status, Control::PENDING_STATUSES, true) ? self::d($days) : null,
                'requirementIds' => $this->requirementIds($requirements),
                'mitigationIds' => $this->attackIds(AttackMitigation::class, $mitigations),
                'riskIds' => $ids($risks, $riskNeedles),
                'decisionIds' => $ids($decisions, $decisionNeedles),
            ]));
            $this->backdate($control, self::d(min(-30, $days)) . ' 10:00:00');
            foreach ($tests as [$ago, $result, $method]) {
                $this->save(new ControlTest(['control_id' => $control->id, 'tested_on' => self::d(-$ago), 'result' => $result,
                    'method' => $method, 'tested_by_id' => $this->users[$owner]]));
            }
            $count++;
        }

        // Device checks: owners, schedules and test history
        $schedule = [
            'disk_encrypted' => ['analyst', 3, [[60, 'effective', 'Intune encryption report, all laptops']]],
            'av_edr_installed' => ['jpatel', 1, [[90, 'effective', 'EDR console coverage report'], [50, 'partial', 'EDR coverage: 2 servers not reporting']]],
            'mdm_enrolled' => ['analyst', 3, [[30, 'effective', 'Intune compliance report']]],
            'firewall_enabled' => ['jpatel', 12, []],
            'patched' => ['jpatel', 1, [[22, 'effective', 'Patch compliance report']]], // next test due in ~9 days
        ];
        foreach ($schedule as $key => [$owner, $months, $tests]) {
            $control = Control::findOne($checks[$key]);
            $this->as($owner);
            $control->owner_id = $this->users[$owner];
            $control->test_frequency_months = $months;
            $control->populateLinks();
            $this->save($control);
            foreach ($tests as [$ago, $result, $method]) {
                $this->save(new ControlTest(['control_id' => $control->id, 'tested_on' => self::d(-$ago), 'result' => $result,
                    'method' => $method, 'tested_by_id' => $this->users[$owner]]));
            }
            $count++;
        }
        $edr = Control::findOne($checks['av_edr_installed']);
        $edr->populateLinks();
        $edr->riskIds = array_merge($edr->riskIds, $ids($risks, ['Windows Server 2012', 'Developer workstation']));
        $this->save($edr);

        // Lessons learned on incidents: what detected them, what failed, what was added afterwards
        $controlId = static fn(string $needle) => Control::find()->select('id')->where(['like', 'title', $needle])->scalar();
        $roles = [
            'Ransomware detected' => ['detectedByControlIds' => ['EDR'], 'failedControlIds' => ['Email filtering'], 'responseControlIds' => ['Immutable offline backups']],
            'quishing' => ['failedControlIds' => ['Security awareness'], 'responseControlIds' => ['Phishing-resistant MFA']],
            'Former employee' => ['failedControlIds' => ['Joiner, mover']],
            'VPN appliance' => ['detectedByControlIds' => ['Vulnerability scanning'], 'failedControlIds' => ['Password-only VPN']],
            'Publicly accessible S3' => ['responseControlIds' => ['Cloud storage guardrails']],
            'PowerShell' => ['detectedByControlIds' => ['EDR', 'Centralised logging']],
        ];
        $this->as('analyst');
        foreach ($roles as $needle => $links) {
            $incident = Incident::findOne($find($incidents, $needle));
            if (!$incident) {
                continue;
            }
            $incident->autoTimeline = false;
            $incident->populateLinks();
            foreach ($links as $property => $controls) {
                $incident->$property = array_values(array_filter(array_map($controlId, $controls)));
            }
            $this->save($incident);
        }

        // Statement of Applicability: what does not apply to this organisation
        $this->as('analyst');
        foreach ([
            'A.8.4' => 'No in-house software development; source code is held by suppliers under contract (see A.5.20).',
            'A.8.25' => 'No in-house software development.',
            'A.8.28' => 'No in-house software development.',
            'A.8.31' => 'No in-house software development.',
        ] as $externalId => $justification) {
            $requirement = FrameworkRequirement::findOne(['external_id' => $externalId]);
            if ($requirement) {
                $requirement->applicable = false;
                $requirement->justification = $justification;
                $this->save($requirement);
            }
        }
        return $count;
    }

    // ---- Cloud Inventory ----------------------------------------------------

    /**
     * Cloud accounts and assets, cloud-check controls (two existing controls gain a
     * cloud check) with mixed results, and links to the public-bucket incident,
     * the cloud storage risk and the Cloudflare decision.
     * @return array{0:int, 1:int, 2:int} accounts, assets, new controls
     */
    private function seedCloud(array $checks, array $risks, array $decisions, array $incidents): array
    {
        $find = static fn(array $records, string $needle) => array_values(array_filter(
            $records, static fn($r) => stripos($r->title, $needle) !== false))[0] ?? null;

        // Cloud checks: two existing controls extend to the cloud, three are new
        $this->as('jpatel');
        $guardrails = Control::find()->where(['like', 'title', 'Cloud storage guardrails'])->one();
        $guardrails->cloud_check = Control::CHECK_MANUAL;
        $guardrails->cloudScopeList = ['bucket'];
        $this->save($guardrails);
        $os = Control::findOne($checks['os_supported']);
        $os->cloud_check = Control::CHECK_OS_SUPPORT;
        $os->cloudScopeList = ['instance'];
        $this->save($os);
        $new = [];
        foreach ([
            'encryption' => ['Encryption at rest for cloud data stores', Control::CHECK_ENCRYPTION,
                array_merge(CloudCatalogue::typesIn('storage'), CloudCatalogue::typesIn('data')),
                'Buckets, volumes, backups and managed databases are encrypted at rest with provider- or customer-managed keys.',
                ['M1041'], ['A.8.24', 'CC6.1', 'PR.DS-01', 'B3.b']],
            'root_mfa' => ['MFA on cloud root and admin accounts', Control::CHECK_MANUAL, [CloudCatalogue::SCOPE_ACCOUNT],
                'Root / global admin accounts use hardware MFA, have no access keys and are only used for break-glass.',
                ['M1032', 'M1026'], ['A.5.17', 'A.8.2', 'A.8.5', 'CE-UA-4', 'CC6.1', 'PR.AA-03', 'B2.a']],
            'audit_logging' => ['Cloud audit logging enabled', Control::CHECK_MANUAL, [CloudCatalogue::SCOPE_ACCOUNT],
                'CloudTrail / activity logs / Workspace audit logs are enabled in every account and sent to the SIEM.',
                ['M1047'], ['A.8.15', 'CC7.2', 'DE.CM-09', 'C1.a']],
        ] as $key => [$title, $method, $scope, $description, $mitigations, $requirements]) {
            $control = $this->save(new Control([
                'title' => $title,
                'description' => $description,
                'control_type' => 'preventive',
                'nature' => 'technical',
                'status' => Control::STATUS_IMPLEMENTED,
                'owner_id' => $this->users['jpatel'],
                'implemented_on' => self::d(-180),
                'test_frequency_months' => 6,
                'cloud_check' => $method,
                'cloudScopeList' => $scope,
                'requirementIds' => $this->requirementIds($requirements),
                'mitigationIds' => $this->attackIds(AttackMitigation::class, $mitigations),
            ]));
            $this->backdate($control, self::d(-180) . ' 10:00:00');
            $new[$key] = $control->id;
        }

        // [name, provider, identifier, environment, owner, criticality, classification, MFA, audit logging]
        $accounts = [];
        foreach ([
            'aws-prod' => ['Production (AWS)', 'aws', '111122223333', 'production', 'jpatel', 'critical', 'confidential', 'pass', 'pass'],
            'aws-dev' => ['Development (AWS)', 'aws', '444455556666', 'development', 'jpatel', 'medium', 'internal', 'fail', ''],
            'cloudflare' => ['Cloudflare', 'cloudflare', '0123456789abcdef0123456789abcdef', 'production', 'jpatel', 'high', 'internal', 'pass', 'pass'],
            'workspace' => ['Google Workspace', 'google_workspace', 'example.com', 'production', 'analyst', 'high', 'confidential', 'pass', 'pass'],
            'github' => ['GitHub organisation', 'github', 'example-org', 'production', 'jpatel', 'high', 'confidential', '', 'pass'],
        ] as $key => [$name, $provider, $identifier, $env, $owner, $criticality, $classification, $mfa, $logging]) {
            $this->as($owner);
            $account = $this->save(new CloudAccount([
                'name' => $name, 'provider' => $provider, 'account_identifier' => $identifier, 'environment' => $env,
                'owner_id' => $this->users[$owner], 'status' => CloudAccount::STATUS_ACTIVE, 'criticality' => $criticality,
                'data_classification' => $classification, 'checks' => [$new['root_mfa'] => $mfa, $new['audit_logging'] => $logging],
            ]));
            $this->backdate($account, self::d(-300) . ' 09:00:00');
            $accounts[$key] = $account;
        }

        // [account, name, type, region, resource id, internet-facing, public IPs, endpoints, encryption, os, version, criticality,
        //  classification, status, bucket public-access result, days since last sync (null = not synced)]
        $rows = [
            ['aws-prod', 'customer-portal-alb', 'load_balancer', 'eu-west-2', 'arn:aws:elasticloadbalancing:eu-west-2:111122223333:loadbalancer/app/customer-portal/50dc6c495c0c9188',
                true, '203.0.113.10, 203.0.113.11', 'portal.example.com', 'unknown', null, null, 'critical', 'confidential', 'active', '', 1],
            ['aws-prod', 'portal-web-01', 'instance', 'eu-west-2', 'arn:aws:ec2:eu-west-2:111122223333:instance/i-0a1b2c3d4e5f60001',
                false, null, null, 'yes', 'Amazon Linux', '2023', 'high', 'confidential', 'active', '', 1],
            ['aws-prod', 'portal-web-02', 'instance', 'eu-west-2', 'arn:aws:ec2:eu-west-2:111122223333:instance/i-0a1b2c3d4e5f60002',
                false, null, null, 'yes', 'Ubuntu', '18.04 LTS', 'high', 'confidential', 'active', '', 1],
            ['aws-prod', 'customer-exports', 'bucket', 'eu-west-2', 'arn:aws:s3:::example-customer-exports',
                false, null, null, 'yes', null, null, 'critical', 'restricted', 'active', 'pass', 1],
            ['aws-prod', 'erp-db', 'database', 'eu-west-2', 'arn:aws:rds:eu-west-2:111122223333:db:erp-db',
                false, null, null, 'yes', null, null, 'critical', 'restricted', 'active', '', 1],
            ['aws-prod', 'prod-vpc', 'vpc', 'eu-west-2', 'arn:aws:ec2:eu-west-2:111122223333:vpc/vpc-0f1e2d3c4b5a69788',
                false, null, null, 'unknown', null, null, 'high', 'internal', 'active', '', 1],
            ['aws-prod', 'portal-alb-sg', 'security_group', 'eu-west-2', 'arn:aws:ec2:eu-west-2:111122223333:security-group/sg-0123456789abcdef0',
                true, null, null, 'unknown', null, null, 'high', 'internal', 'active', '', 1],
            ['aws-prod', 'backup-vault', 'backup', 'eu-west-2', 'arn:aws:backup:eu-west-2:111122223333:backup-vault:prod-vault',
                false, null, null, 'yes', null, null, 'critical', 'restricted', 'active', '', 1],
            ['aws-prod', 'ci-deploy-role', 'iam_role', 'global', 'arn:aws:iam::111122223333:role/ci-deploy',
                false, null, null, 'unknown', null, null, 'high', 'internal', 'active', '', 1],
            ['aws-prod', 'legacy-reports', 'bucket', 'eu-west-1', 'arn:aws:s3:::example-legacy-reports',
                true, null, 'example-legacy-reports.s3.eu-west-1.amazonaws.com', 'no', null, null, 'medium', 'confidential', 'active', 'fail', 45],
            ['aws-dev', 'dev-sandbox-01', 'instance', 'eu-west-2', 'arn:aws:ec2:eu-west-2:444455556666:instance/i-0dev0000000000001',
                true, '198.51.100.40', null, 'unknown', 'Windows Server', '2012 R2', 'low', 'internal', 'stopped', '', null],
            ['aws-dev', 'dev-artifacts', 'bucket', 'eu-west-2', 'arn:aws:s3:::example-dev-artifacts',
                false, null, null, 'unknown', null, null, 'low', 'internal', 'active', '', null],
            ['cloudflare', 'example.com', 'dns_zone', 'global', '023e105f4ecef8ad9ca31a8372d0c353',
                true, null, 'example.com', 'unknown', null, null, 'high', 'public', 'active', '', null],
            ['cloudflare', 'Portal WAF ruleset', 'waf', 'global', 'portal-waf-custom-rules',
                true, null, 'portal.example.com', 'unknown', null, null, 'high', 'internal', 'active', '', null],
            ['github', 'infrastructure', 'repository', 'global', 'example-org/infrastructure',
                false, null, null, 'unknown', null, null, 'high', 'confidential', 'active', '', null],
        ];
        $this->as('jpatel');
        $assets = [];
        foreach ($rows as [$acc, $name, $type, $region, $resourceId, $exposed, $ips, $endpoints, $encryption, $os, $version, $criticality, $classification, $status, $public, $seen]) {
            $asset = $this->save(new CloudAsset([
                'account_id' => $accounts[$acc]->id, 'name' => $name, 'resource_type' => $type, 'region' => $region, 'resource_id' => $resourceId,
                'internet_facing' => $exposed, 'public_ips' => $ips, 'public_endpoints' => $endpoints, 'encryption_at_rest' => $encryption,
                'os' => $os, 'os_version' => $version, 'criticality' => $criticality, 'data_classification' => $classification, 'status' => $status,
                'owner_id' => $this->users['jpatel'],
                'source' => $seen === null ? null : 'aws', 'source_id' => $seen === null ? null : $resourceId,
                'first_seen_at' => $seen === null ? null : self::d(-200) . ' 06:00:00',
                'last_seen_at' => $seen === null ? null : self::d(-$seen) . ' 06:00:00',
                'checks' => $type === 'bucket' ? [$guardrails->id => $public] : [],
            ]));
            $this->backdate($asset, self::d(-200) . ' 09:00:00');
            $assets[$name] = $asset;
        }

        // Links (recorded on the risk / incident / decision side; inserted directly so their histories stay as seeded)
        $db = Yii::$app->db;
        $link = static function (string $table, string $column, ?ActiveRecord $owner, array $names) use ($db, $assets) {
            if ($owner) {
                $db->createCommand()->batchInsert($table, [$column, 'cloud_asset_id', 'created_at'],
                    array_map(static fn($n) => [$owner->id, $assets[$n]->id, Actor::now()], $names))->execute();
            }
        };
        $link('incident_cloud_asset', 'incident_id', $find($incidents, 'Publicly accessible S3'), ['customer-exports']);
        $link('risk_cloud_asset', 'risk_id', $find($risks, 'Misconfigured cloud storage'), ['customer-exports', 'legacy-reports', 'dev-artifacts']);
        $link('decision_cloud_asset', 'decision_id', $find($decisions, 'Cloudflare for DDoS'), ['customer-portal-alb', 'Portal WAF ruleset', 'example.com']);
        return [count($accounts), count($assets), count($new)];
    }

    private function linkDecisions(array $decisions, array $incidents): void
    {
        $map = [8 => [8], 9 => [5], 12 => [13], 10 => [3], 2 => [1, 14], 15 => [], 4 => [18], 11 => [6]];
        $this->as('analyst');
        foreach ($map as $d => $incs) {
            $decision = Decision::findOne($decisions[$d]->id);
            $decision->incidentIds = array_map(static fn($n) => $incidents[$n]->id, $incs);
            $decision->populateLinks();
            $this->save($decision);
        }
    }
}
