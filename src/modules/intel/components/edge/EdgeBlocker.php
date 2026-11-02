<?php

namespace app\modules\intel\components\edge;

use app\components\Actor;
use app\modules\intel\components\Cloudflare;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\EdgeFinding;
use RuntimeException;
use Yii;

/**
 * Blocks the addresses behind edge findings: each finding is linked to the
 * blocklist entry already in force for its address, or to a new inbound entry
 * (added to Cloudflare when it is configured), and closed as Blocked.
 *
 * Several findings are blocked with one Cloudflare update, so blocking a page
 * of findings costs the same API calls as blocking one.
 */
final class EdgeBlocker
{
    /** Block lengths offered (days; 0 = permanent) */
    public const DAYS = [1, 7, 30, 90, 0];

    /** A requested block length, or the configured default when it is out of range */
    public static function days(mixed $days): int
    {
        $days = (int) $days;
        return in_array($days, self::DAYS, true) || ($days > 0 && $days <= 365) ? $days : EdgeSettings::blockDays();
    }

    /** days => label, shortest first, permanent last (includes the configured default) */
    public static function dayOptions(): array
    {
        $out = [];
        foreach (array_unique(array_merge(self::DAYS, [EdgeSettings::blockDays()])) as $d) {
            $out[$d] = $d === 0 ? 'Permanently' : sprintf('For %d day%s', $d, $d === 1 ? '' : 's');
        }
        uksort($out, static fn($a, $b) => ($a ?: PHP_INT_MAX) <=> ($b ?: PHP_INT_MAX));
        return $out;
    }

    /**
     * @param EdgeFinding[] $findings
     * @return array{blocked: EdgeFinding[], linked: array<string, string>, skipped: EdgeFinding[], failed: array<string, string>, notice: string}
     *   blocked: findings closed against a new entry; linked: finding reference => reference of the entry already in force;
     *   skipped: findings already blocked by an entry in force; failed: finding reference => why (nothing is changed for those); notice: Cloudflare outcome
     */
    public static function block(array $findings, int $days): array
    {
        $out = ['blocked' => [], 'linked' => [], 'skipped' => [], 'failed' => [], 'notice' => ''];
        $cloudflare = CloudflareTraffic::isConfigured();
        $expires = $days > 0 ? date('Y-m-d H:i:s', strtotime(Actor::now() . " +$days days")) : null;
        /** @var array<int, array{0: EdgeFinding, 1: BlocklistEntry}> $new */
        $new = [];

        foreach ($findings as $finding) {
            if ($finding->status === EdgeFinding::STATUS_BLOCKED && $finding->blocklistEntry?->getIsInForce()) {
                $out['skipped'][] = $finding;
                continue;
            }
            $existing = array_values(array_filter(BlocklistEntry::findForIp($finding->ip), static fn(BlocklistEntry $b) => $b->getIsInForce()));
            if ($existing) {
                $finding->blocklist_id = $existing[0]->id;
                $finding->resolve(EdgeFinding::STATUS_BLOCKED);
                $out['linked'][$finding->reference] = $existing[0]->reference;
                continue;
            }
            $entry = new BlocklistEntry([
                'value' => $finding->ip,
                'severity' => $finding->priority === 'high' ? 'high' : 'medium',
                'direction' => 'inbound',
                'reason' => mb_substr(sprintf('Edge finding %s: %s.', $finding->reference, $finding->headline ?: 'suspicious traffic'), 0, 2000),
                'incident_id' => $finding->incident_id,
                'expires_at' => $expires,
                'cloudflare' => $cloudflare,
            ]);
            // An address blocked before but since unblocked or expired is a new entry; only an exact duplicate fails validation
            if (!$entry->validate()) {
                $out['failed'][$finding->reference] = implode(' ', $entry->getErrorSummary(true));
                continue;
            }
            $new[] = [$finding, $entry];
        }
        if (!$new) {
            return $out;
        }

        if ($cloudflare) {
            $add = [];
            foreach ($new as [, $entry]) {
                $add[$entry->value] = 'Greywarden: ' . $entry->reason;
            }
            try {
                $result = Cloudflare::sync($add, [], BlocklistEntry::cloudflareAsns());
            } catch (RuntimeException $e) {
                foreach ($new as [$finding]) {
                    $out['failed'][$finding->reference] = $e->getMessage();
                }
                return $out;
            }
            $out['notice'] = sprintf('Added to the Cloudflare list “%s” (rule “%s”).', Cloudflare::listName(), Cloudflare::ruleName())
                . ($result['created'] ? ' ' . Cloudflare::createdRuleMessage()
                    : (!$result['enabled'] ? ' The rule is disabled in Cloudflare, so it is not blocking yet.' : ''));
        }

        Yii::$app->db->transaction(static function () use ($new, $cloudflare, &$out) {
            foreach ($new as [$finding, $entry]) {
                $entry->cloudflare_added_at = $cloudflare ? Actor::now() : null;
                $entry->save(false);
                $finding->blocklist_id = $entry->id;
                $finding->populateRelation('blocklistEntry', $entry);
                $finding->resolve(EdgeFinding::STATUS_BLOCKED);
                $out['blocked'][] = $finding;
            }
        });
        return $out;
    }
}
