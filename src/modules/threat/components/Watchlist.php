<?php

namespace app\modules\threat\components;

use app\components\Actor;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\models\CloudAccount;
use app\modules\inventory\models\Device;
use app\modules\supplier\models\Supplier;
use app\modules\threat\models\ThreatWatchTerm;
use Yii;

/**
 * An organisation's watchlist as a text matcher, and keeping it in step with the assets
 * Greywarden knows about.
 *
 * Terms match whole words. Terms of up to four letters match their exact case, so "Duo"
 * is the product and not "the duo", and "AWS" is not "aws" inside a slug.
 */
final class Watchlist
{
    public const EXACT_CASE_LENGTH = 4;

    /**
     * Device types whose maker says what is running. The brand of a laptop or phone does not (the
     * operating system does), and "Google" or "Apple" would match far too much.
     */
    private const MAKER_DEVICE_TYPES = ['firewall', 'network', 'server', 'printer', 'iot'];

    /** Values that say nothing about the technology */
    private const NOT_A_PRODUCT = ['other', 'unknown', 'none', 'n/a', 'na', 'generic', 'custom', 'various', 'test'];

    /** @var array<string, string> term => regular expression */
    private array $patterns = [];

    /** @param string[] $terms */
    public function __construct(array $terms)
    {
        foreach ($terms as $term) {
            $this->patterns[$term] = self::pattern($term);
        }
    }

    public static function forOrganisation(int $organisationId): self
    {
        return new self(ThreatWatchTerm::find()->select('term')->where(['organisation_id' => $organisationId, 'is_enabled' => 1])->column());
    }

    private static function pattern(string $term): string
    {
        $flags = mb_strlen($term) <= self::EXACT_CASE_LENGTH ? 'u' : 'iu';
        return '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/' . $flags;
    }

    /** @return string[] the terms found in the text */
    public function match(string $text): array
    {
        $found = [];
        foreach ($this->patterns as $term => $pattern) {
            if (stripos($text, $term) !== false && preg_match($pattern, $text) === 1) {
                $found[] = $term;
            }
        }
        return $found;
    }

    /**
     * Terms from what the organisation runs: operating systems, the makers of infrastructure
     * devices, cloud providers and suppliers in service. The registers are not per-organisation yet, so every
     * organisation gets the same; they need scoping here when they are.
     *
     * @return array<string, array{0: string, 1: string}> term => [category, origin]
     */
    public static function derived(): array
    {
        $terms = [];
        $devices = Device::find()->active()->andWhere(['status' => Device::STATUS_ACTIVE])->select(['device_type', 'manufacturer', 'os'])->distinct()->asArray()->all();
        foreach ($devices as $device) {
            $maker = in_array($device['device_type'], self::MAKER_DEVICE_TYPES, true) ? self::productName((string) $device['manufacturer']) : null;
            foreach ([$maker, self::osFamily((string) $device['os'])] as $term) {
                if ($term !== null) {
                    $terms[$term] = ['device', ThreatWatchTerm::ORIGIN_DEVICE];
                }
            }
        }
        foreach (CloudAccount::find()->active()->select('provider')->distinct()->column() as $provider) {
            if ($term = self::productName(CloudCatalogue::providerLabel($provider))) {
                $terms[$term] = ['platform', ThreatWatchTerm::ORIGIN_CLOUD];
            }
        }
        foreach (Supplier::find()->active()->andWhere(['status' => Supplier::IN_SERVICE_STATUSES])->select('name')->column() as $name) {
            if ($term = self::supplierName((string) $name)) {
                $terms[$term] = ['supplier', ThreatWatchTerm::ORIGIN_SUPPLIER];
            }
        }
        return $terms;
    }

    private static function productName(string $value): ?string
    {
        $value = trim($value);
        return mb_strlen($value) < 2 || mb_strlen($value) > 120 || in_array(mb_strtolower($value), self::NOT_A_PRODUCT, true) ? null : $value;
    }

    /** "macOS 14.2" and "Mac OS X" are both macOS; "Windows Server 2022" is Windows Server */
    public static function osFamily(string $os): ?string
    {
        $os = trim($os);
        if (preg_match('/^mac\s*os/i', $os)) {
            return 'macOS';
        }
        if (preg_match('/^i(pad)?os\b/i', $os, $m)) {
            return isset($m[1]) && $m[1] !== '' ? 'iPadOS' : 'iOS';
        }
        return self::productName((string) preg_replace('/\s+v?\d[\d.]*(\s.*)?$/', '', $os));
    }

    /** The trading name: "Acme Hosting Ltd" is reported as "Acme Hosting" */
    public static function supplierName(string $name): ?string
    {
        do {
            $before = $name;
            $name = trim((string) preg_replace('/[\s,]+(ltd|limited|inc|incorporated|llc|llp|plc|gmbh|corp|corporation|co|sarl|sas|bv|b\.v|emea|uk|europe)\.?$/i', '', $name));
        } while ($name !== $before);
        return self::productName($name);
    }

    /**
     * Brings the asset-derived terms up to date: new ones are added, and ones no longer
     * in the registers are removed unless someone switched them off (so that decision is kept).
     * A term that already exists from any origin is left as it is.
     *
     * @return array{added: int, removed: int}
     */
    public static function sync(int $organisationId): array
    {
        $db = Yii::$app->db;
        $derived = self::derived();
        $wanted = [];
        foreach ($derived as $term => $definition) {
            $wanted[mb_strtolower($term)] = [$term, ...$definition];
        }
        $existing = (new \yii\db\Query())->select(['id', 'term', 'origin', 'is_enabled'])->from('threat_watch_term')->where(['organisation_id' => $organisationId])->all();
        $remove = [];
        foreach ($existing as $row) {
            $key = mb_strtolower($row['term']);
            if (in_array($row['origin'], ThreatWatchTerm::DERIVED_ORIGINS, true) && !isset($wanted[$key]) && $row['is_enabled']) {
                $remove[] = (int) $row['id'];
            }
            unset($wanted[$key]);
        }
        if ($remove) {
            $db->createCommand()->delete('threat_watch_term', ['id' => $remove])->execute();
        }
        if ($wanted) {
            $now = Actor::now();
            $db->createCommand()->batchInsert('threat_watch_term', ['organisation_id', 'term', 'category', 'origin', 'is_enabled', 'created_at', 'updated_at'],
                array_map(static fn(array $t) => [$organisationId, $t[0], $t[1], $t[2], 1, $now, $now], array_values($wanted)))->execute();
        }
        return ['added' => count($wanted), 'removed' => count($remove)];
    }
}
