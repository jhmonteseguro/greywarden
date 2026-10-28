<?php

use app\components\ReferenceGenerator;
use app\components\Migration;
use yii\db\Query;

/**
 * Adds the "Supported operating systems" device-check control: a device fails
 * once its OS is past the vendor end of support (OsLifecycle), so it becomes
 * non-compliant. Skipped when a supported-OS check already exists.
 */
class m261028_000005_add_supported_os_device_check extends Migration
{
    private const TITLE = 'Supported operating systems';

    public function safeUp()
    {
        if ((new Query())->from('control')->where(['device_check' => 'os_support'])->exists($this->db)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->insert('control', [
            'reference' => ReferenceGenerator::next('control', 'reference', 'CTL', 4, $this->db),
            'title' => self::TITLE,
            'description' => 'Devices run an operating system that still receives security updates from its vendor. Unsupported systems are upgraded, replaced or moved out of scope.',
            'implementation' => 'Checked automatically against each device\'s operating system and version in the Device Inventory.',
            'control_type' => 'preventive',
            'nature' => 'technical',
            'status' => 'implemented',
            'effectiveness' => 'not_tested',
            'device_check' => 'os_support',
            // the device types with an OS in the end-of-support catalogue; network gear, printers and IoT are not assessed
            'device_types' => 'laptop,desktop,server,vm,mobile,tablet',
            'device_ownership' => 'all',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->db->getLastInsertID();
        foreach ((new Query())->select('id')->from('attack_mitigation')->where(['external_id' => 'M1051'])->column($this->db) as $mid) {
            $this->insert('control_attack_mitigation', ['control_id' => $id, 'mitigation_id' => $mid, 'created_at' => $now]);
        }
        $requirements = ['A.8.8', 'CE-SU-1', 'CE-SU-2', 'CC7.1', 'PR.PS-02', 'B4.d'];
        foreach ((new Query())->select('id')->from('framework_requirement')->where(['external_id' => $requirements])->column($this->db) as $rid) {
            $this->insert('control_framework_requirement', ['control_id' => $id, 'requirement_id' => $rid, 'created_at' => $now]);
        }
    }

    public function safeDown()
    {
        $this->delete('control', ['title' => self::TITLE, 'device_check' => 'os_support']);
    }
}
