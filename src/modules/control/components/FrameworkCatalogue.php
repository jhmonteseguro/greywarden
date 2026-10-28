<?php

namespace app\modules\control\components;

use app\models\Setting;
use Yii;
use yii\db\Connection;
use yii\db\Query;

/**
 * The bundled framework catalogues (data/frameworks): ISO/IEC 27001:2022
 * Annex A, Cyber Essentials, the SOC 2 Trust Services Criteria, the NIST
 * CSF 2.0 Core and the NCSC Cyber Assessment Framework.
 *
 * Each framework can be switched off in Admin › Settings, and SOC 2 is scoped
 * to the Trust Services categories chosen there (Security is always in). A
 * requirement outside that scope is hidden everywhere, but keeps its
 * applicability and control links for when it is switched back on.
 *
 * import() upserts requirements by framework + id, so a refreshed catalogue
 * keeps each requirement's applicability, justification and control links.
 */
final class FrameworkCatalogue
{
    public const ISO27001 = 'iso27001';
    public const CYBER_ESSENTIALS = 'cyber_essentials';
    public const SOC2 = 'soc2';
    public const NIST_CSF = 'nist_csf';
    public const NCSC_CAF = 'ncsc_caf';

    public const SOC2_SECURITY = 'security';
    public const SOC2_AVAILABILITY = 'availability';
    public const SOC2_CONFIDENTIALITY = 'confidentiality';
    public const SOC2_PROCESSING_INTEGRITY = 'processing_integrity';
    public const SOC2_PRIVACY = 'privacy';

    /** Setting holding the optional SOC 2 categories in scope (comma separated) */
    public const SETTING_SOC2_CATEGORIES = 'framework.soc2.categories';

    private const FRAMEWORKS = [
        self::ISO27001 => [
            'label' => 'ISO/IEC 27001:2022', 'short' => 'ISO 27001', 'file' => 'iso27001-2022.php', 'slug' => 'iso27001',
            'nav' => 'ISO 27001 SoA', 'icon' => 'patch-check', 'title' => 'ISO 27001 Statement of Applicability',
        ],
        self::CYBER_ESSENTIALS => [
            'label' => 'Cyber Essentials', 'short' => 'Cyber Essentials', 'file' => 'cyber-essentials.php', 'slug' => 'cyber-essentials',
            'nav' => 'Cyber Essentials', 'icon' => 'patch-check-fill', 'title' => 'Cyber Essentials readiness',
        ],
        self::SOC2 => [
            'label' => 'SOC 2', 'short' => 'SOC 2', 'file' => 'soc2-tsc-2017.php', 'slug' => 'soc2',
            'nav' => 'SOC 2', 'icon' => 'award', 'title' => 'SOC 2 readiness',
        ],
        self::NIST_CSF => [
            'label' => 'NIST CSF 2.0', 'short' => 'NIST CSF', 'file' => 'nist-csf-2.0.php', 'slug' => 'nist-csf',
            'nav' => 'NIST CSF 2.0', 'icon' => 'compass', 'title' => 'NIST CSF 2.0 profile',
        ],
        self::NCSC_CAF => [
            'label' => 'NCSC CAF 4.0', 'short' => 'NCSC CAF', 'file' => 'ncsc-caf-4.0.php', 'slug' => 'ncsc-caf',
            'nav' => 'NCSC CAF', 'icon' => 'building-check', 'title' => 'NCSC CAF assessment',
        ],
    ];

    /** SOC 2 Trust Services category => criteria id prefix */
    private const SOC2_PREFIXES = [
        self::SOC2_SECURITY => 'CC',
        self::SOC2_AVAILABILITY => 'A',
        self::SOC2_CONFIDENTIALITY => 'C',
        self::SOC2_PROCESSING_INTEGRITY => 'PI',
        self::SOC2_PRIVACY => 'P',
    ];

    /** @return string[] every bundled framework, switched on or not */
    public static function frameworks(): array
    {
        return array_keys(self::FRAMEWORKS);
    }

    /** @return string[] the frameworks switched on in Admin › Settings */
    public static function enabled(): array
    {
        return array_values(array_filter(self::frameworks(), [self::class, 'isEnabled']));
    }

    /** Switched on unless an admin turned it off */
    public static function isEnabled(string $framework): bool
    {
        return isset(self::FRAMEWORKS[$framework]) && Setting::get(self::settingName($framework), '1') !== '0';
    }

    /** Setting holding whether a framework is switched on ('1' / '0') */
    public static function settingName(string $framework): string
    {
        return 'framework.' . $framework;
    }

    public static function label(string $framework): string
    {
        return self::FRAMEWORKS[$framework]['label'] ?? $framework;
    }

    public static function shortLabel(string $framework): string
    {
        return self::FRAMEWORKS[$framework]['short'] ?? $framework;
    }

    /** Sidebar label */
    public static function navLabel(string $framework): string
    {
        return self::FRAMEWORKS[$framework]['nav'] ?? $framework;
    }

    /** bootstrap-icons name */
    public static function icon(string $framework): string
    {
        return self::FRAMEWORKS[$framework]['icon'] ?? 'patch-check';
    }

    /** Title of the framework's page */
    public static function title(string $framework): string
    {
        return self::FRAMEWORKS[$framework]['title'] ?? self::label($framework);
    }

    public static function slug(string $framework): string
    {
        return self::FRAMEWORKS[$framework]['slug'] ?? $framework;
    }

    public static function fromSlug(string $slug): ?string
    {
        foreach (self::FRAMEWORKS as $framework => $def) {
            if ($def['slug'] === $slug) {
                return $framework;
            }
        }
        return null;
    }

    /** @return array{framework:string, version:string, groups:array<string,string>, requirements:array} */
    public static function read(string $framework): array
    {
        return require Yii::getAlias('@app/data/frameworks/') . self::FRAMEWORKS[$framework]['file'];
    }

    public static function version(string $framework): string
    {
        return self::read($framework)['version'];
    }

    // ---- SOC 2 scope --------------------------------------------------------

    /** @return array<string,string> Trust Services category => label */
    public static function soc2Categories(): array
    {
        return [
            self::SOC2_SECURITY => 'Security',
            self::SOC2_AVAILABILITY => 'Availability',
            self::SOC2_CONFIDENTIALITY => 'Confidentiality',
            self::SOC2_PROCESSING_INTEGRITY => 'Processing integrity',
            self::SOC2_PRIVACY => 'Privacy',
        ];
    }

    /** @return array<string,string> the categories an admin can add to the scope (all but Security) */
    public static function soc2OptionalCategories(): array
    {
        return array_diff_key(self::soc2Categories(), [self::SOC2_SECURITY => true]);
    }

    /** @return string[] categories in scope: Security plus those chosen in Admin › Settings */
    public static function soc2Scope(): array
    {
        $chosen = explode(',', (string) Setting::get(self::SETTING_SOC2_CATEGORIES, ''));
        return array_merge([self::SOC2_SECURITY], array_keys(array_intersect_key(self::soc2OptionalCategories(), array_flip($chosen))));
    }

    /** "CC6.1" -> security, "PI1.3" -> processing_integrity, "P4.2" -> privacy */
    public static function soc2Category(string $groupCode): ?string
    {
        $prefix = preg_replace('/\d.*$/', '', $groupCode);
        $category = array_search($prefix, self::SOC2_PREFIXES, true);
        return $category === false ? null : $category;
    }

    /** @return string[] SOC 2 group codes (CC1…P8) of the categories in scope */
    public static function soc2Groups(): array
    {
        $scope = self::soc2Scope();
        return array_values(array_filter(array_keys(self::read(self::SOC2)['groups']),
            static fn($group) => in_array(self::soc2Category($group), $scope, true)));
    }

    /** Whether requirements of this framework and group are shown */
    public static function inScope(string $framework, string $groupCode): bool
    {
        if (!self::isEnabled($framework)) {
            return false;
        }
        return $framework !== self::SOC2 || in_array(self::soc2Category($groupCode), self::soc2Scope(), true);
    }

    /**
     * Query condition on framework_requirement for the requirements in scope:
     * frameworks switched on, and only the chosen SOC 2 categories.
     * @param string $alias table alias to qualify the columns with
     */
    public static function scopeCondition(string $alias = 'framework_requirement'): array
    {
        $condition = ['or', '0=1'];
        foreach (self::enabled() as $framework) {
            $condition[] = $framework === self::SOC2
                ? ['and', ["$alias.framework" => $framework], ["$alias.group_code" => self::soc2Groups()]]
                : ["$alias.framework" => $framework];
        }
        return $condition;
    }

    // ---- Import -------------------------------------------------------------

    /** @return array<string,int> framework => requirements upserted */
    public static function import(Connection $db): array
    {
        $stats = [];
        foreach (self::frameworks() as $framework) {
            $data = self::read($framework);
            $existing = (new Query())->select(['id', 'external_id'])->from('framework_requirement')
                ->where(['framework' => $framework])->indexBy('external_id')->column($db);
            $order = 0;
            foreach ($data['requirements'] as $row) {
                [$id, $title] = $row;
                $group = self::groupCode($framework, $id);
                $values = [
                    'group_code' => $group,
                    'group_name' => $data['groups'][$group] ?? $group,
                    'title' => $title,
                    'description' => $row[2] ?? null,
                    'sort_order' => ++$order,
                ];
                if (isset($existing[$id])) {
                    $db->createCommand()->update('framework_requirement', $values, ['id' => $existing[$id]])->execute();
                } else {
                    $db->createCommand()->insert('framework_requirement', $values + ['framework' => $framework, 'external_id' => $id])->execute();
                }
            }
            $stats[$framework] = $order;
        }
        return $stats;
    }

    /** "A.5.12" -> "5", "CE-FW-3" -> "FW", "CC6.1" -> "CC6", "GV.OC-01" -> "GV.OC", "B4.d" -> "B4" */
    public static function groupCode(string $framework, string $externalId): string
    {
        return match ($framework) {
            self::ISO27001 => explode('.', $externalId)[1] ?? '',
            self::SOC2, self::NCSC_CAF => explode('.', $externalId)[0],
            self::NIST_CSF => explode('-', $externalId)[0],
            default => explode('-', $externalId)[1] ?? '',
        };
    }
}
