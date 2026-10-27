<?php

namespace app\modules\control\components;

use Yii;
use yii\db\Connection;
use yii\db\Query;

/**
 * The bundled framework catalogues (data/frameworks): ISO/IEC 27001:2022
 * Annex A and Cyber Essentials.
 *
 * import() upserts requirements by framework + id, so a refreshed catalogue
 * keeps each requirement's applicability, justification and control links.
 */
final class FrameworkCatalogue
{
    public const ISO27001 = 'iso27001';
    public const CYBER_ESSENTIALS = 'cyber_essentials';

    /** framework => [label, short label, data file, URL slug] */
    private const FRAMEWORKS = [
        self::ISO27001 => ['ISO/IEC 27001:2022', 'ISO 27001', 'iso27001-2022.php', 'iso27001'],
        self::CYBER_ESSENTIALS => ['Cyber Essentials', 'Cyber Essentials', 'cyber-essentials.php', 'cyber-essentials'],
    ];

    public static function frameworks(): array
    {
        return array_keys(self::FRAMEWORKS);
    }

    public static function label(string $framework): string
    {
        return self::FRAMEWORKS[$framework][0] ?? $framework;
    }

    public static function shortLabel(string $framework): string
    {
        return self::FRAMEWORKS[$framework][1] ?? $framework;
    }

    public static function slug(string $framework): string
    {
        return self::FRAMEWORKS[$framework][3] ?? $framework;
    }

    public static function fromSlug(string $slug): ?string
    {
        foreach (self::FRAMEWORKS as $framework => $def) {
            if ($def[3] === $slug) {
                return $framework;
            }
        }
        return null;
    }

    /** @return array{framework:string, version:string, groups:array<string,string>, requirements:array} */
    public static function read(string $framework): array
    {
        return require Yii::getAlias('@app/data/frameworks/') . self::FRAMEWORKS[$framework][2];
    }

    public static function version(string $framework): string
    {
        return self::read($framework)['version'];
    }

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

    /** "A.5.12" -> "5", "CE-FW-3" -> "FW" */
    public static function groupCode(string $framework, string $externalId): string
    {
        return $framework === self::ISO27001
            ? explode('.', $externalId)[1] ?? ''
            : explode('-', $externalId)[1] ?? '';
    }
}
