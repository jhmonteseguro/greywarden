<?php

namespace app\modules\threat\components;

use app\components\Actor;
use app\modules\threat\models\ThreatSource;
use Yii;

/**
 * The built-in sources. Definitions live here so an upgrade can fix or add one; what
 * changes at runtime (enabled, health) lives in threat_source. sync() brings the two
 * together and is run by the migration and at the start of every pull.
 *
 * quiet: too many items to read, so they stay out of the queue unless they match the
 * watchlist or are in KEV. exclude: a regular expression for titles that are not news.
 * off: starts switched off, for a feed that does not work everywhere.
 */
final class ThreatCatalogue
{
    private const NEWS = 30;
    private const OFFICIAL = 60;
    private const RESEARCH = 180;

    /** key => [name, category, url, minutes between fetches, options] */
    private const SOURCES = [
        'cisa-kev' => ['CISA Known Exploited Vulnerabilities', ThreatSource::CATEGORY_EXPLOITED, 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json', self::OFFICIAL, ['format' => ThreatSource::FORMAT_KEV, 'quiet' => true]],

        'msrc' => ['Microsoft Security Update Guide', ThreatSource::CATEGORY_ADVISORY, 'https://api.msrc.microsoft.com/update-guide/rss', self::RESEARCH, ['quiet' => true]],
        'zdi-published' => ['Zero Day Initiative: published', ThreatSource::CATEGORY_ADVISORY, 'https://www.zerodayinitiative.com/rss/published/', self::RESEARCH, ['quiet' => true]],
        'zdi-upcoming' => ['Zero Day Initiative: upcoming', ThreatSource::CATEGORY_ADVISORY, 'https://www.zerodayinitiative.com/rss/upcoming/', self::RESEARCH, ['quiet' => true]],

        'cisa-advisories' => ['CISA Advisories', ThreatSource::CATEGORY_GOVERNMENT, 'https://www.cisa.gov/cybersecurity-advisories/all.xml', self::OFFICIAL, ['off' => true]],
        'ncsc-uk' => ['NCSC UK', ThreatSource::CATEGORY_GOVERNMENT, 'https://www.ncsc.gov.uk/api/1/services/v1/all-rss-feed.xml', self::OFFICIAL, []],
        'cert-eu' => ['CERT-EU', ThreatSource::CATEGORY_GOVERNMENT, 'https://cert.europa.eu/publications/security-advisories-rss', self::OFFICIAL, []],

        'google-ti' => ['Google Threat Intelligence', ThreatSource::CATEGORY_RESEARCH, 'https://cloudblog.withgoogle.com/topics/threat-intelligence/rss/', self::RESEARCH, []],
        'microsoft-security' => ['Microsoft Security Blog', ThreatSource::CATEGORY_RESEARCH, 'https://www.microsoft.com/en-us/security/blog/feed/', self::RESEARCH, []],
        'talos' => ['Cisco Talos', ThreatSource::CATEGORY_RESEARCH, 'https://blog.talosintelligence.com/rss/', self::RESEARCH, []],
        'unit42' => ['Unit 42', ThreatSource::CATEGORY_RESEARCH, 'https://unit42.paloaltonetworks.com/feed/', self::RESEARCH, []],
        'securelist' => ['Securelist', ThreatSource::CATEGORY_RESEARCH, 'https://securelist.com/feed/', self::RESEARCH, []],
        'eset' => ['ESET WeLiveSecurity', ThreatSource::CATEGORY_RESEARCH, 'https://www.welivesecurity.com/en/rss/feed/', self::RESEARCH, []],
        'sentinellabs' => ['SentinelLabs', ThreatSource::CATEGORY_RESEARCH, 'https://www.sentinelone.com/labs/feed/', self::RESEARCH, []],
        'checkpoint' => ['Check Point Research', ThreatSource::CATEGORY_RESEARCH, 'https://research.checkpoint.com/feed/', self::RESEARCH, []],

        'project-zero' => ['Google Project Zero', ThreatSource::CATEGORY_EXPLOIT, 'https://projectzero.google/feed.xml', self::RESEARCH, []],
        'watchtowr' => ['watchTowr Labs', ThreatSource::CATEGORY_EXPLOIT, 'https://labs.watchtowr.com/rss/', self::RESEARCH, []],
        'horizon3' => ['Horizon3.ai', ThreatSource::CATEGORY_EXPLOIT, 'https://horizon3.ai/feed/', self::RESEARCH, []],
        'rapid7' => ['Rapid7', ThreatSource::CATEGORY_EXPLOIT, 'https://www.rapid7.com/blog/rss/', self::RESEARCH, []],
        'dfir-report' => ['The DFIR Report', ThreatSource::CATEGORY_EXPLOIT, 'https://thedfirreport.com/feed/', self::RESEARCH, []],

        'bleepingcomputer' => ['BleepingComputer', ThreatSource::CATEGORY_NEWS, 'https://www.bleepingcomputer.com/feed/', self::NEWS, []],
        'the-record' => ['The Record', ThreatSource::CATEGORY_NEWS, 'https://therecord.media/feed', self::NEWS, []],
        'securityweek' => ['SecurityWeek', ThreatSource::CATEGORY_NEWS, 'https://www.securityweek.com/feed/', self::NEWS, []],
        'krebs' => ['Krebs on Security', ThreatSource::CATEGORY_NEWS, 'https://krebsonsecurity.com/feed/', self::NEWS, []],
        'hacker-news' => ['The Hacker News', ThreatSource::CATEGORY_NEWS, 'https://feeds.feedburner.com/TheHackersNews', self::NEWS, []],
        'dark-reading' => ['Dark Reading', ThreatSource::CATEGORY_NEWS, 'https://www.darkreading.com/rss.xml', self::NEWS, []],
        'risky-business' => ['Risky Business', ThreatSource::CATEGORY_NEWS, 'https://risky.biz/feeds/risky-business-news/', self::NEWS, []],
        'sans-isc' => ['SANS Internet Storm Center', ThreatSource::CATEGORY_NEWS, 'https://isc.sans.edu/rssfeed_full.xml', self::NEWS, ['exclude' => '/^ISC Stormcast/i']],
        'schneier' => ['Schneier on Security', ThreatSource::CATEGORY_NEWS, 'https://www.schneier.com/feed/atom/', self::NEWS, []],
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::SOURCES);
    }

    /** Regular expression for titles to skip from this source, if any */
    public static function exclude(string $key): ?string
    {
        return self::SOURCES[$key][4]['exclude'] ?? null;
    }

    /**
     * Adds new sources and updates the definition of existing ones, leaving whether they are
     * enabled and their health alone. A source dropped from the catalogue is switched off.
     */
    public static function sync(): void
    {
        $db = Yii::$app->db;
        $now = Actor::now();
        $existing = array_flip((new \yii\db\Query())->select('key')->from('threat_source')->where(['is_custom' => 0])->column($db));
        foreach (self::SOURCES as $key => [$name, $category, $url, $minutes, $options]) {
            $definition = [
                'name' => $name, 'category' => $category, 'url' => $url, 'interval_minutes' => $minutes,
                'format' => $options['format'] ?? ThreatSource::FORMAT_RSS, 'is_quiet' => (int) ($options['quiet'] ?? false),
            ];
            if (isset($existing[$key])) {
                $db->createCommand()->update('threat_source', $definition, ['key' => $key])->execute();
                unset($existing[$key]);
            } else {
                $db->createCommand()->insert('threat_source', $definition + ['key' => $key, 'is_custom' => 0, 'is_enabled' => (int) empty($options['off']), 'created_at' => $now, 'updated_at' => $now])->execute();
            }
        }
        if ($existing) {
            $db->createCommand()->update('threat_source', ['is_enabled' => 0], ['key' => array_keys($existing), 'is_custom' => 0])->execute();
        }
    }
}
