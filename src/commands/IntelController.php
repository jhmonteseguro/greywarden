<?php

namespace app\commands;

use app\modules\intel\components\Cloudflare;
use app\modules\intel\components\FeedCatalogue;
use app\modules\intel\components\Feeds;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\Investigator;
use app\modules\intel\models\BlocklistEntry;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Threat intel maintenance.
 *
 *   php yii intel/status                  # local feed status
 *   php yii intel/update                  # download feeds that are stale
 *   php yii intel/update --force=1        # download every feed now
 *   php yii intel/lookup 203.0.113.7      # investigate from the command line (also AS64496)
 *   php yii intel/expire-blocks           # take expired blocklist entries out of Cloudflare
 *   php yii intel/cloudflare-migrate      # move "Add to Cloudflare" entries from the old rule to the IP list + "Greywarden Blocklist" rule
 *       [--old-rule="Block Bad Actors"] [--dry-run=1]
 */
class IntelController extends Controller
{
    public $defaultAction = 'status';

    /** @var bool re-download all feeds, not only stale ones */
    public $force = false;
    /** @var bool bypass cached source answers (intel/lookup) */
    public $refresh = false;
    /** @var string|null the rule "Add to Cloudflare" used before (intel/cloudflare-migrate; default CLOUDFLARE_RULE_NAME or "Block Bad Actors") */
    public $oldRule;
    /** @var bool only check and show the plan (intel/cloudflare-migrate) */
    public $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'update' => ['force'],
            'lookup' => ['refresh'],
            'cloudflare-migrate' => ['oldRule', 'dryRun'],
            default => [],
        });
    }

    public function actionStatus(): int
    {
        $status = Feeds::status();
        foreach (FeedCatalogue::all() as $key => $def) {
            $row = $status[$key] ?? null;
            $line = sprintf("  %-18s %9s entries  %s", $key, $row ? number_format((int) $row['entry_count']) : '-', $row && $row['fetched_at'] ? 'updated ' . $row['fetched_at'] : 'never downloaded');
            $this->stdout($line . ($row && $row['last_error'] ? '  ERROR: ' . $row['last_error'] : '') . "\n", $row && $row['last_error'] ? Console::FG_RED : ($row && $row['fetched_at'] ? Console::FG_GREEN : Console::FG_YELLOW));
        }
        $stale = Feeds::staleKeys(false);
        $this->stdout($stale ? 'Stale: ' . implode(', ', $stale) . " (run: php yii intel/update)\n" : "All feeds current.\n");
        return ExitCode::OK;
    }

    public function actionUpdate(): int
    {
        $this->stdout($this->force ? "Downloading all feeds…\n" : "Downloading stale feeds…\n");
        $results = Feeds::update(null, (bool) $this->force);
        if (!$results) {
            $this->stdout("Nothing to update (or another update is running).\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }
        $failed = 0;
        foreach ($results as $key => $r) {
            if ($r['ok']) {
                $this->stdout(sprintf("  %-18s %9s entries  %5d ms\n", $key, number_format($r['count']), $r['ms']), Console::FG_GREEN);
            } else {
                $failed++;
                $this->stdout(sprintf("  %-18s FAILED: %s\n", $key, $r['error']), Console::FG_RED);
            }
        }
        return $failed ? ExitCode::UNAVAILABLE : ExitCode::OK;
    }

    public function actionExpireBlocks(): int
    {
        $result = BlocklistEntry::removeExpiredFromCloudflare();
        foreach ($result['removed'] as $ref) {
            $this->stdout("  $ref taken out of Cloudflare\n", Console::FG_GREEN);
        }
        foreach ($result['failed'] as $ref => $error) {
            $this->stderr("  $ref: $error\n", Console::FG_RED);
        }
        if (!$result['removed'] && !$result['failed']) {
            $this->stdout("No expired entries in Cloudflare.\n");
        }
        return $result['failed'] ? ExitCode::UNAVAILABLE : ExitCode::OK;
    }

    /**
     * One-off move to the IP list: every entry in Cloudflare is pushed to the list (addresses,
     * networks) or the "Greywarden Blocklist" rule (ASNs), expired ones are dropped, and their
     * conditions are taken out of the old rule.
     */
    public function actionCloudflareMigrate(): int
    {
        $oldRule = $this->oldRule ?: (gw_env('CLOUDFLARE_RULE_NAME') ?: Cloudflare::LEGACY_RULE_NAME);
        $entries = BlocklistEntry::find()->active()->andWhere(['not', ['cloudflare_added_at' => null]])->orderBy(['id' => SORT_ASC])->all();
        $inForce = array_values(array_filter($entries, static fn(BlocklistEntry $e) => $e->getIsInForce()));
        $expired = array_values(array_filter($entries, static fn(BlocklistEntry $e) => !$e->getIsInForce()));
        $ips = array_filter($inForce, static fn(BlocklistEntry $e) => $e->indicator_type !== BlocklistEntry::TYPE_ASN);
        $asns = array_filter($inForce, static fn(BlocklistEntry $e) => $e->indicator_type === BlocklistEntry::TYPE_ASN);
        $this->stdout(sprintf("%d address(es) / network(s) → list \"%s\", %d ASN(s) → rule \"%s\", %d expired entr%s dropped; old rule \"%s\".\n",
            count($ips), Cloudflare::listName(), count($asns), Cloudflare::ruleName(), count($expired), count($expired) === 1 ? 'y' : 'ies', $oldRule));

        try {
            if ($this->dryRun) {
                $check = Cloudflare::check();
                $this->stdout($check['rule']
                    ? sprintf("Rule \"%s\": found (%s, action %s).\n", Cloudflare::ruleName(), ($check['rule']['enabled'] ?? true) ? 'enabled' : 'disabled', $check['rule']['action'] ?? '?')
                    : sprintf("Rule \"%s\": missing; it will be created, disabled.\n", Cloudflare::ruleName()));
                $this->stdout($check['list'] ? sprintf("List \"%s\": found, %d item(s).\n", Cloudflare::listName(), $check['items']) : sprintf("List \"%s\": missing; it will be created.\n", Cloudflare::listName()));
                $this->stdout("Dry run: nothing changed.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }
            $add = [];
            foreach ($ips as $e) {
                $add[$e->value] = $e->reference . ': ' . $e->reason;
            }
            $result = Cloudflare::sync($add, [], array_map(static fn(BlocklistEntry $e) => (int) $e->asn, $asns));
            $this->stdout(sprintf("List and rule updated.%s\n", $result['created'] ? ' ' . Cloudflare::createdRuleMessage() : (!$result['enabled'] ? ' The rule is disabled in Cloudflare: enable it to block.' : '')), Console::FG_GREEN);
            foreach ($expired as $e) {
                $e->cloudflare_added_at = null;
                $e->cloudflare = false;
                $e->save(false);
            }
            $old = Cloudflare::stripRule($oldRule, array_map(static fn(BlocklistEntry $e) => $e->getCloudflareClause(), $entries));
        } catch (\RuntimeException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }
        if (!$old['found']) {
            $this->stdout(sprintf("No rule named \"%s\": nothing to clean up.\n", $oldRule));
        } elseif ($old['empty']) {
            $this->stdout(sprintf("Every condition in \"%s\" was Greywarden's: delete that rule in Cloudflare (Security › WAF › Custom rules).\n", $oldRule), Console::FG_YELLOW);
        } else {
            $this->stdout(sprintf("%d condition(s) taken out of \"%s\"; its other conditions were kept.\n", $old['removed'], $oldRule), Console::FG_GREEN);
        }
        foreach ($old['manual'] as $clause) {
            $this->stdout("  Remove by hand from \"$oldRule\": $clause\n", Console::FG_YELLOW);
        }
        return ExitCode::OK;
    }

    public function actionLookup(string $indicator): int
    {
        $i = Indicator::parse($indicator);
        if (!$i) {
            $this->stderr("Not an IP address or ASN: $indicator\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $report = Investigator::run($i, (bool) $this->refresh);
        $a = $report->assessment();
        $color = match ($a->tone()) { 'critical', 'high' => Console::FG_RED, 'medium' => Console::FG_YELLOW, 'success' => Console::FG_GREEN, default => Console::FG_CYAN };
        $this->stdout(sprintf("%s  %s  score %d  confidence %d%%\n", $i->value, strtoupper($a->ratingLabel()), $a->score, $a->confidence), $color, Console::BOLD);
        $this->stdout($a->headline . "\n\n");
        foreach ($a->signals as $s) {
            $this->stdout(sprintf("  %+4d%s %-55s %s\n", $s['weight'], $s['counted'] ? ' ' : '*', mb_strimwidth($s['label'], 0, 55, '…'), $s['sourceLabel']));
        }
        $this->stdout("\n");
        foreach ($report->results as $key => $r) {
            $this->stdout(sprintf("  %-11s %-12s %6s  %s\n", $key, $r->status, $r->cached ? 'cache' : round($r->time * 1000) . 'ms', mb_strimwidth($r->summary, 0, 80, '…')));
        }
        $this->stdout(sprintf("\n%d sources in %.1fs  (* corroborating signal, not counted twice)\n", count($report->results), $report->duration));
        return ExitCode::OK;
    }
}
