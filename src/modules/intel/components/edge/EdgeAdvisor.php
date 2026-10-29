<?php

namespace app\modules\intel\components\edge;

use app\components\Actor;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\EdgeFinding;
use yii\db\Expression;

/**
 * What to do about edge findings:
 *
 *   recommendations()  the actions for one finding, most important first
 *   wafRule()          one Cloudflare custom rule that blocks the probe paths
 *                      seen recently, for everyone (block the behaviour, not
 *                      the addresses: scanners rotate)
 *   asnClusters()      networks with several findings, worth blocking or
 *                      challenging as a whole
 */
final class EdgeAdvisor
{
    public const LOOKBACK_DAYS = 30;
    public const CLUSTER_MIN = 3;

    /**
     * @return array[] [key, tone, icon, title, detail, action (block | incident | dismiss | ignore | waf | asn | null), asn?]
     */
    public static function recommendations(EdgeFinding $finding): array
    {
        $out = [];
        $signals = array_column($finding->getSignalList(), null, 'key');
        $open = $finding->getIsOpen();
        $intelBad = in_array($finding->intel_rating, ['malicious', 'suspicious'], true);

        if (isset($signals['exposure']) && !$finding->incident_id) {
            $out[] = ['key' => 'incident', 'tone' => 'critical', 'icon' => 'fire', 'action' => 'incident',
                'title' => 'Raise an incident and check the exposed path',
                'detail' => $signals['exposure']['label'] . '. Request it yourself from outside your network; if it really is served, take it down, rotate any secrets it holds and look for other requests to it.'];
        }
        if (isset($signals['rule-gap'])) {
            $out[] = ['key' => 'rule-gap', 'tone' => 'high', 'icon' => 'cloud-slash', 'action' => null,
                'title' => sprintf('Check the “%s” rule in Cloudflare', Cloudflare::ruleName()),
                'detail' => $signals['rule-gap']['detail']];
        }
        if ($open && !isset($signals['rule-gap']) && ($finding->score >= 40 || $intelBad || isset($signals['exposure']) || isset($signals['payload']))) {
            $out[] = ['key' => 'block', 'tone' => 'high', 'icon' => 'slash-circle', 'action' => 'block',
                'title' => sprintf('Block the address for %d days', EdgeSettings::blockDays()),
                'detail' => 'Scanner addresses change often, so a time-limited block keeps the Cloudflare rule short. '
                    . (Cloudflare::isConfigured() ? 'It is added to the Cloudflare rule and taken out again when it expires.' : 'Cloudflare is not configured, so it is only recorded on the blocklist.')];
        }
        if (array_intersect_key($signals, ['probe' => 1, 'enumeration' => 1])) {
            $out[] = ['key' => 'waf', 'tone' => 'medium', 'icon' => 'shield-plus', 'action' => 'waf',
                'title' => 'Block the probe paths for everyone',
                'detail' => 'One custom rule that blocks paths your sites never serve stops this scan from every address, including the next one. See the suggested rule on the Edge findings page.'];
        }
        if (isset($signals['volume'])) {
            $out[] = ['key' => 'rate-limit', 'tone' => 'medium', 'icon' => 'speedometer', 'action' => null,
                'title' => 'Add a rate-limiting rule',
                'detail' => 'Cloudflare rate limiting (Security › WAF › Rate limiting rules) caps requests per address for all clients, which handles floods better than blocking them one at a time.'];
        }
        if ($finding->asn && ($cluster = self::asnClusters()[$finding->asn] ?? null) && !$cluster['blocked']) {
            $out[] = ['key' => 'asn', 'tone' => 'medium', 'icon' => 'diagram-3', 'action' => 'asn', 'asn' => $finding->asn,
                'title' => sprintf('AS%d has %d findings in %d days', $finding->asn, $cluster['count'], self::LOOKBACK_DAYS),
                'detail' => 'When one hosting network keeps sending scanners, block or challenge the whole ASN. Check first that none of your customers or suppliers use it.'];
        }
        if ($open && (!empty($signals['agent']) && str_starts_with($signals['agent']['short'], 'Survey'))) {
            $out[] = ['key' => 'research', 'tone' => 'info', 'icon' => 'eye', 'action' => 'ignore',
                'title' => 'Ignore it if it is an internet survey scanner',
                'detail' => 'Research scanners (Censys, Shodan and the like) index everything. Blocking them only hides your exposure from their reports; ignoring the address stops it coming back here.'];
        }
        if ($open && !$out) {
            $out[] = ['key' => 'dismiss', 'tone' => 'info', 'icon' => 'check2-circle', 'action' => 'dismiss',
                'title' => 'Dismiss it if it looks harmless',
                'detail' => 'Low-scoring findings are usually opportunistic noise. A dismissed finding comes back if the address returns and scores high enough again.'];
        }
        return $out;
    }

    /**
     * Suggested custom rule for the probe groups seen in recent findings.
     *
     * @return array{groups: array<string, array{label: string, findings: int, paths: array<string, int>}>, expression: ?string}
     */
    public static function wafRule(): array
    {
        $since = date('Y-m-d H:i:s', strtotime(Actor::now() . ' -' . self::LOOKBACK_DAYS . ' days'));
        $ignored = EdgeSettings::ignoredPaths();
        $groups = [];
        $findings = EdgeFinding::find()->select(['id', 'evidence'])->where(['>=', 'last_seen_at', $since])
            ->andWhere(['<>', 'status', EdgeFinding::STATUS_IGNORED])->orderBy(['last_seen_at' => SORT_DESC])->limit(500)->asArray()->all();
        foreach ($findings as $row) {
            $paths = (json_decode((string) $row['evidence'], true) ?: [])['paths'] ?? [];
            $seen = [];
            foreach ($paths as $path => $info) {
                $group = $info['group'] ?? null;
                if ($group === null || EdgeSettings::isIgnoredPath((string) $path, $ignored)) {
                    continue;
                }
                $groups[$group] ??= ['label' => EdgeCatalogue::groupLabel($group), 'findings' => 0, 'paths' => []];
                $groups[$group]['paths'][$path] = ($groups[$group]['paths'][$path] ?? 0) + (int) $info['count'];
                $seen[$group] = true;
            }
            foreach (array_keys($seen) as $group) {
                $groups[$group]['findings']++;
            }
        }
        uasort($groups, static fn($a, $b) => $b['findings'] <=> $a['findings']);
        $conditions = [];
        foreach ($groups as $key => &$group) {
            arsort($group['paths']);
            $group['paths'] = array_slice($group['paths'], 0, 8, true);
            if ($condition = EdgeCatalogue::ruleCondition($key, $ignored)) {
                $conditions[] = $condition;
            }
        }
        unset($group);
        return ['groups' => $groups, 'expression' => $conditions ? implode(' or ', $conditions) : null];
    }

    /**
     * Networks with at least CLUSTER_MIN findings in the lookback, not blocked as a whole yet.
     *
     * @return array<int, array{count: int, open: int, blocked: bool}> asn => counts, most findings first
     */
    public static function asnClusters(): array
    {
        $since = date('Y-m-d H:i:s', strtotime(Actor::now() . ' -' . self::LOOKBACK_DAYS . ' days'));
        $rows = EdgeFinding::find()->select(['asn', 'count' => new Expression('COUNT(*)'), 'open' => new Expression("SUM(status = 'new')")])
            ->where(['not', ['asn' => null]])->andWhere(['>=', 'last_seen_at', $since])->andWhere(['<>', 'status', EdgeFinding::STATUS_IGNORED])
            ->groupBy('asn')->having(['>=', new Expression('COUNT(*)'), self::CLUSTER_MIN])->orderBy(['count' => SORT_DESC])->asArray()->all();
        $out = [];
        foreach ($rows as $row) {
            $asn = (int) $row['asn'];
            $blocked = (bool) array_filter(BlocklistEntry::findForAsn($asn), static fn(BlocklistEntry $b) => $b->getIsInForce());
            $out[$asn] = ['count' => (int) $row['count'], 'open' => (int) $row['open'], 'blocked' => $blocked];
        }
        return $out;
    }
}
