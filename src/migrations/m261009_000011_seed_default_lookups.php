<?php

use app\components\Migration;

/**
 * Default lookup values. Admins can edit these under Admin > Lookups.
 */
class m261009_000011_seed_default_lookups extends Migration
{
    public function safeUp()
    {
        $now = date('Y-m-d H:i:s');
        $sets = [
            'risk_category' => [
                'Cyber Security', 'Information Governance', 'Operational', 'Physical Security',
                'Third Party / Supplier', 'Compliance & Legal', 'People', 'Business Continuity',
            ],
            'decision_category' => [
                'Architecture', 'Policy', 'Risk Acceptance', 'Tooling', 'Vendor / Procurement', 'Process', 'Exception',
            ],
            'incident_category' => [
                'Malware', 'Phishing', 'Unauthorised Access', 'Data Breach', 'Denial of Service',
                'Lost/Stolen Device', 'Insider/Policy Violation', 'Vulnerability Exploitation', 'Other',
            ],
        ];
        $rows = [];
        foreach ($sets as $type => $names) {
            foreach ($names as $i => $name) {
                $rows[] = [$type, $name, ($i + 1) * 10, 1, $now, $now];
            }
        }
        $this->batchInsert('lookup', ['type', 'name', 'sort_order', 'is_active', 'created_at', 'updated_at'], $rows);
    }

    public function safeDown()
    {
        $this->delete('lookup', ['type' => ['risk_category', 'decision_category', 'incident_category']]);
    }
}
