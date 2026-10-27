<?php

use app\components\Migration;
use app\components\ReferenceGenerator;
use yii\db\Query;

/**
 * Replaces the fixed device posture columns (disk_encrypted, av_edr_installed,
 * mdm_enrolled, firewall_enabled + the patch-age rule) with device-check
 * controls in the Control Register.
 *
 * Each column becomes a control that applies to the device types it makes
 * sense for; ticked boxes become "pass" results and unticked ones "fail".
 * Synced devices with no box ticked were never assessed (the Google export has
 * no posture data), so they get no result: "not assessed".
 */
class m261020_000003_move_device_posture_to_controls extends Migration
{
    private const ENDPOINTS = 'laptop,desktop,server,vm';

    /** column => control definition (column null = the patch-age check) */
    private function definitions(): array
    {
        $maxAge = (int) (Yii::$app->params['device.patchMaxAgeDays'] ?? 30);
        return [
            'disk_encrypted' => [
                'title' => 'Full-disk encryption on devices',
                'control_type' => 'preventive',
                'device_check' => 'manual',
                'device_types' => 'laptop,desktop,server,vm,mobile,tablet',
                'description' => 'Device storage is encrypted, so data on a lost or stolen device cannot be read.',
                'mitigations' => ['M1041'],
                'requirements' => ['A.8.1', 'A.8.24'],
            ],
            'av_edr_installed' => [
                'title' => 'Anti-malware / EDR on endpoints',
                'control_type' => 'detective',
                'device_check' => 'manual',
                'device_types' => self::ENDPOINTS,
                'description' => 'Anti-malware or EDR is installed, up to date and reporting.',
                'mitigations' => ['M1049', 'M1040'],
                'requirements' => ['A.8.7', 'CE-MP-1', 'CE-MP-2'],
            ],
            'mdm_enrolled' => [
                'title' => 'Devices enrolled in MDM',
                'control_type' => 'preventive',
                'device_check' => 'manual',
                'device_types' => 'laptop,desktop,mobile,tablet',
                'description' => 'The device is enrolled in mobile device management, which enforces the configuration baseline.',
                'mitigations' => ['M1028', 'M1033'],
                'requirements' => ['A.8.1', 'A.8.9'],
            ],
            'firewall_enabled' => [
                'title' => 'Host firewall enabled',
                'control_type' => 'preventive',
                'device_check' => 'manual',
                'device_types' => self::ENDPOINTS,
                'description' => 'The host-based firewall is on and blocks unsolicited inbound connections.',
                'mitigations' => ['M1037'],
                'requirements' => ['A.8.20', 'CE-FW-1', 'CE-FW-7'],
            ],
            'patched' => [
                'title' => 'Security updates applied promptly',
                'control_type' => 'preventive',
                'device_check' => 'patch_age',
                'device_types' => null,
                'check_max_age_days' => $maxAge,
                'description' => sprintf('Devices were last patched within %d days. Measured from each device\'s "Last patched" date.', $maxAge),
                'mitigations' => ['M1051'],
                'requirements' => ['A.8.8', 'CE-SU-3', 'CE-SU-4'],
            ],
        ];
    }

    public function safeUp()
    {
        $now = date('Y-m-d H:i:s');
        $controlIds = [];
        foreach ($this->definitions() as $column => $def) {
            $this->insert('control', [
                'reference' => ReferenceGenerator::next('control', 'reference', 'CTL', 4, $this->db),
                'title' => $def['title'],
                'description' => $def['description'],
                'implementation' => 'Created from the fixed device posture check it replaced. Checked on each device in the Device Inventory.',
                'control_type' => $def['control_type'],
                'nature' => 'technical',
                'status' => 'implemented',
                'effectiveness' => 'not_tested',
                'device_check' => $def['device_check'],
                'device_types' => $def['device_types'],
                'device_ownership' => 'all',
                'check_max_age_days' => $def['check_max_age_days'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->getLastInsertID();
            $controlIds[$column] = $id;

            $mitigations = (new Query())->select('id')->from('attack_mitigation')->where(['external_id' => $def['mitigations']])->column($this->db);
            foreach ($mitigations as $mid) {
                $this->insert('control_attack_mitigation', ['control_id' => $id, 'mitigation_id' => $mid, 'created_at' => $now]);
            }
            $requirements = (new Query())->select('id')->from('framework_requirement')->where(['external_id' => $def['requirements']])->column($this->db);
            foreach ($requirements as $rid) {
                $this->insert('control_framework_requirement', ['control_id' => $id, 'requirement_id' => $rid, 'created_at' => $now]);
            }
        }

        $columns = ['disk_encrypted', 'av_edr_installed', 'mdm_enrolled', 'firewall_enabled'];
        $defs = $this->definitions();
        $rows = [];
        foreach ((new Query())->select(array_merge(['id', 'device_type', 'source'], $columns))->from('device')->each(200, $this->db) as $device) {
            $ticked = array_filter($columns, static fn($c) => (bool) $device[$c]);
            if ($device['source'] !== null && !$ticked) {
                continue; // synced and never assessed
            }
            foreach ($columns as $c) {
                $types = $defs[$c]['device_types'];
                if ($types !== null && !in_array($device['device_type'], explode(',', $types), true)) {
                    continue;
                }
                $rows[] = [$device['id'], $controlIds[$c], $device[$c] ? 'pass' : 'fail'];
            }
        }
        if ($rows) {
            $this->batchInsert('device_control', ['device_id', 'control_id', 'result'], $rows);
        }
        echo sprintf("    > %d device-check controls, %d device results\n", count($controlIds), count($rows));

        foreach ($columns as $c) {
            $this->dropColumn('device', $c);
        }
    }

    public function safeDown()
    {
        $columns = ['disk_encrypted', 'av_edr_installed', 'mdm_enrolled', 'firewall_enabled'];
        foreach ($columns as $c) {
            $this->addColumn('device', $c, $this->boolean()->notNull()->defaultValue(false)->after('data_classification'));
        }
        $defs = $this->definitions();
        foreach ($columns as $c) {
            $controlId = (new Query())->select('id')->from('control')->where(['title' => $defs[$c]['title'], 'device_check' => 'manual'])->scalar($this->db);
            if ($controlId) {
                $pass = (new Query())->select('device_id')->from('device_control')->where(['control_id' => $controlId, 'result' => 'pass']);
                $this->update('device', [$c => 1], ['id' => $pass]);
            }
        }
        $this->delete('control', ['title' => array_column($defs, 'title'), 'device_check' => ['manual', 'patch_age']]);
    }
}
