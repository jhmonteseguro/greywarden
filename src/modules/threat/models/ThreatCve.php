<?php

namespace app\modules\threat\models;

use app\components\Actor;
use Yii;
use yii\db\ActiveRecord;

/**
 * What is known about a CVE: CISA KEV listing, CVSS (NVD) and EPSS (FIRST). Shared facts.
 *
 * @property string $cve_id
 * @property string|null $description
 * @property string|null $vendor
 * @property string|null $product
 * @property string|null $kev_added_on
 * @property string|null $kev_due_on
 * @property bool|int $kev_ransomware
 * @property string|null $kev_action
 * @property string|null $cvss_score
 * @property string|null $cvss_severity
 * @property string|null $nvd_checked_at
 * @property string|null $epss_score
 * @property string|null $epss_percentile
 * @property string|null $epss_checked_at
 * @property string $created_at
 */
class ThreatCve extends ActiveRecord
{
    /** CVE ids as written in text. The sequence number can be long, but a feed must not be able to overflow the column. */
    public const PATTERN = '/\bCVE-\d{4}-\d{4,19}\b/i';

    public static function tableName(): string
    {
        return 'threat_cve';
    }

    public static function primaryKey(): array
    {
        return ['cve_id'];
    }

    /** @return string[] the distinct CVE ids in a text, upper case, in order of appearance */
    public static function extract(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);
        return array_values(array_unique(array_map('strtoupper', $matches[0])));
    }

    /** Makes sure a row exists for each id */
    public static function ensure(array $ids): void
    {
        if (!$ids) {
            return;
        }
        $known = self::find()->select('cve_id')->where(['cve_id' => $ids])->column();
        $missing = array_diff($ids, $known);
        if ($missing) {
            $now = Actor::now();
            Yii::$app->db->createCommand()->batchInsert(self::tableName(), ['cve_id', 'created_at'], array_map(static fn($id) => [$id, $now], array_values($missing)))->execute();
        }
    }

    public function getInKev(): bool
    {
        return $this->kev_added_on !== null;
    }

    public function getNvdUrl(): string
    {
        return 'https://nvd.nist.gov/vuln/detail/' . rawurlencode($this->cve_id);
    }

    /** EPSS as a percentage, e.g. "94.3%"; null when not known */
    public function getEpssPercent(): ?string
    {
        return $this->epss_score === null ? null : rtrim(rtrim(number_format((float) $this->epss_score * 100, 1), '0'), '.') . '%';
    }
}
