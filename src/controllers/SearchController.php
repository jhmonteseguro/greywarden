<?php

namespace app\controllers;

use app\components\Rbac;
use app\modules\attack\models\AttackMitigation;
use app\modules\attack\models\AttackTechnique;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\models\FrameworkRequirement;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\intel\components\Indicator;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\EdgeFinding;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;

/**
 * Global search across all registers (reference, title, hostname, serial...)
 * the MITRE ATT&CK catalogue (T1566, M1032, technique / mitigation names) and the
 * blocklist. An IP address or "AS<number>" also offers a threat intel investigation.
 */
class SearchController extends Controller
{
    public const LIMIT = 25;

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => [Rbac::PERM_VIEW]]],
            ],
        ];
    }

    public function actionIndex(string $q = ''): Response|string
    {
        $q = trim(mb_substr($q, 0, 100));
        $results = [];
        // Only unambiguous indicators (an IP, or AS + digits) offer an investigation
        $investigate = $q !== '' && !ctype_digit($q) ? Indicator::parse($q) : null;
        if ($q !== '') {
            // Exact reference / asset tag / ATT&CK id match: jump straight to the record
            foreach ([Risk::class, Control::class, Decision::class, Incident::class, Device::class, CloudAsset::class, CloudAccount::class, Supplier::class, BlocklistEntry::class, AttackTechnique::class, AttackMitigation::class, FrameworkRequirement::class] as $class) {
                $query = $class::find()->where([$class::referenceAttribute() => $q]);
                if ($class === FrameworkRequirement::class) {
                    $query->andWhere(FrameworkCatalogue::scopeCondition());   // not frameworks switched off
                }
                $exact = $query->one();
                if ($exact) {
                    return $this->redirect($exact->getViewUrl());
                }
            }

            $results = [
                'risks' => ['label' => 'Risks', 'icon' => 'exclamation-triangle', 'items' => Risk::find()
                    ->where(['or', ['like', 'reference', $q], ['like', 'title', $q], ['like', 'scope', $q]])
                    ->orderBy(['archived_at' => SORT_ASC, 'id' => SORT_DESC])->limit(self::LIMIT)->all()],
                'controls' => ['label' => 'Controls', 'icon' => 'shield-shaded', 'items' => Control::find()
                    ->where(['or', ['like', 'reference', $q], ['like', 'title', $q], ['like', 'description', $q]])
                    ->orderBy(['archived_at' => SORT_ASC, 'id' => SORT_ASC])->limit(self::LIMIT)->all()],
                'requirements' => ['label' => implode(' / ', array_map([FrameworkCatalogue::class, 'shortLabel'], FrameworkCatalogue::enabled())), 'icon' => 'patch-check', 'items' => FrameworkRequirement::find()
                    ->where(['or', ['like', 'external_id', $q], ['like', 'title', $q]])->andWhere(FrameworkCatalogue::scopeCondition())
                    ->orderBy(['framework' => SORT_DESC, 'sort_order' => SORT_ASC])->limit(self::LIMIT)->all()],
                'decisions' => ['label' => 'Decisions', 'icon' => 'journal-check', 'items' => Decision::find()
                    ->where(['or', ['like', 'reference', $q], ['like', 'title', $q], ['like', 'decision_makers', $q]])
                    ->orderBy(['archived_at' => SORT_ASC, 'id' => SORT_DESC])->limit(self::LIMIT)->all()],
                'incidents' => ['label' => 'Incidents', 'icon' => 'fire', 'items' => Incident::find()
                    ->where(['or', ['like', 'reference', $q], ['like', 'title', $q], ['like', 'reported_by', $q]])
                    ->orderBy(['archived_at' => SORT_ASC, 'id' => SORT_DESC])->limit(self::LIMIT)->all()],
                'devices' => ['label' => 'Devices', 'icon' => 'pc-display', 'items' => Device::find()
                    ->where(['or',
                        ['like', 'asset_tag', $q], ['like', 'name', $q], ['like', 'hostname', $q], ['like', 'serial_number', $q],
                        ['like', 'assigned_to', $q], ['like', 'assigned_email', $q], ['like', 'ip_addresses', $q], ['like', 'mac_address', $q],
                    ])
                    ->orderBy(['archived_at' => SORT_ASC, 'asset_tag' => SORT_ASC])->limit(self::LIMIT)->all()],
                'cloud-assets' => ['label' => 'Cloud assets', 'icon' => 'cloud', 'items' => CloudAsset::find()
                    ->where(['or',
                        ['like', 'reference', $q], ['like', 'name', $q], ['like', 'resource_id', $q], ['like', 'public_ips', $q], ['like', 'public_endpoints', $q],
                    ])
                    ->orderBy(['archived_at' => SORT_ASC, 'reference' => SORT_ASC])->limit(self::LIMIT)->all()],
                'cloud-accounts' => ['label' => 'Cloud accounts', 'icon' => 'cloud-check', 'items' => CloudAccount::find()
                    ->where(['or', ['like', 'reference', $q], ['like', 'name', $q], ['like', 'account_identifier', $q]])
                    ->orderBy(['archived_at' => SORT_ASC, 'reference' => SORT_ASC])->limit(self::LIMIT)->all()],
                'suppliers' => ['label' => 'Suppliers', 'icon' => 'buildings', 'items' => Supplier::find()
                    ->where(['or', ['like', 'reference', $q], ['like', 'name', $q], ['like', 'legal_name', $q], ['like', 'website', $q], ['like', 'services', $q]])
                    ->orderBy(['archived_at' => SORT_ASC, 'name' => SORT_ASC])->limit(self::LIMIT)->all()],
                'techniques' => ['label' => 'ATT&CK techniques', 'icon' => 'crosshair', 'items' => AttackTechnique::find()->with('parent')
                    ->where(['or', ['like', 'external_id', $q], ['like', 'name', $q]])
                    ->orderBy(['is_deprecated' => SORT_ASC, 'is_subtechnique' => SORT_ASC, 'external_id' => SORT_ASC])->limit(self::LIMIT)->all()],
                'mitigations' => ['label' => 'ATT&CK mitigations', 'icon' => 'shield-check', 'items' => AttackMitigation::find()
                    ->where(['or', ['like', 'external_id', $q], ['like', 'name', $q]])
                    ->orderBy(['is_deprecated' => SORT_ASC, 'external_id' => SORT_ASC])->limit(self::LIMIT)->all()],
                'blocklist' => ['label' => 'Blocklist', 'icon' => 'slash-circle', 'items' => $investigate && $investigate->isIp()
                    ? BlocklistEntry::findForIp($investigate->value)
                    : BlocklistEntry::find()->where(['or', ['like', 'reference', $q], ['like', 'value', $q], ['like', 'reason', $q]])
                        ->orderBy(['archived_at' => SORT_ASC, 'id' => SORT_DESC])->limit(self::LIMIT)->all()],
                'edge' => ['label' => 'Edge findings', 'icon' => 'binoculars', 'items' => EdgeFinding::find()
                    ->where(['or', ['like', 'reference', $q], ['like', 'ip', $q]])
                    ->orderBy(['last_seen_at' => SORT_DESC])->limit(self::LIMIT)->all()],
            ];
            if (!FrameworkCatalogue::enabled()) {
                unset($results['requirements']);
            }
        }
        return $this->render('index', ['q' => $q, 'results' => $results, 'investigate' => $investigate]);
    }
}
