<?php

namespace app\commands;

use app\components\Actor;
use app\models\User;
use app\modules\intel\components\edge\CloudflareTraffic;
use app\modules\intel\components\edge\EdgePuller;
use app\modules\intel\components\edge\EdgeSettings;
use app\modules\intel\models\EdgeFinding;
use app\modules\intel\models\EdgePull;
use RuntimeException;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Edge findings from the Cloudflare traffic analytics.
 *
 *   php yii edge/pull                       # traffic since the last pull (the scheduler container runs it every EDGE_PULL_INTERVAL minutes)
 *   php yii edge/pull --minutes=60          # the last hour instead
 *   php yii edge/pull --user=jdoe           # record jdoe as the actor (default: system)
 *   php yii edge/pull --dry-run=1           # show what would change, save nothing
 *   php yii edge/status                     # configuration, plan limits and recent pulls
 */
class EdgeController extends Controller
{
    public $defaultAction = 'status';

    /** @var int|null window in minutes, instead of "since the last pull" */
    public $minutes;
    /** @var string|null username or email recorded in the audit trail */
    public $user;
    /** @var bool roll everything back */
    public $dryRun = false;
    /** @var bool check new findings against the intel sources */
    public $enrich = true;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'pull' ? ['minutes', 'user', 'dryRun', 'enrich'] : []);
    }

    public function actionPull(): int
    {
        if ($this->minutes !== null && ((int) $this->minutes < 1 || (int) $this->minutes > 10080)) {
            $this->stderr("--minutes must be between 1 and 10080.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $actor = null;
        if ($this->user !== null) {
            $actor = User::findByLogin($this->user);
            if (!$actor) {
                $this->stderr("User '{$this->user}' not found (give a username or email).\n", Console::FG_RED);
                return ExitCode::DATAERR;
            }
        }
        Actor::override($actor?->id, $actor?->username);
        $puller = new EdgePuller();
        $puller->minutes = $this->minutes !== null ? (int) $this->minutes : null;
        $puller->dryRun = (bool) $this->dryRun;
        $puller->enrich = (bool) $this->enrich;
        try {
            $pull = $puller->run();
        } catch (RuntimeException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_YELLOW);
            return ExitCode::TEMPFAIL;
        } finally {
            Actor::override(null);
        }

        if ($pull->getIsError()) {
            $this->stderr('Pull failed: ' . $pull->message . "\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }
        $this->stdout(sprintf("%s%s → %s: %s requests from %d addresses, %d new finding(s), %d updated.\n",
            $this->dryRun ? '[dry run] ' : '', $pull->window_start, $pull->window_end, number_format($pull->requests), $pull->ips, $pull->findings_new, $pull->findings_updated), Console::FG_GREEN);
        foreach ($puller->changes as $ip => $change) {
            $this->stdout(sprintf("  %-9s %s\n", $change, $ip), $change === 'updated' ? Console::FG_CYAN : Console::FG_YELLOW);
        }
        if ($pull->message) {
            $this->stdout($pull->message . "\n", Console::FG_YELLOW);
        }
        return ExitCode::OK;
    }

    public function actionStatus(): int
    {
        if (!CloudflareTraffic::isConfigured()) {
            $this->stdout("Cloudflare: not configured (set CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID in .env).\n", Console::FG_YELLOW);
        } else {
            try {
                $settings = CloudflareTraffic::settings(true);
                foreach (['http' => CloudflareTraffic::HTTP, 'firewall' => CloudflareTraffic::FIREWALL] as $key => $dataset) {
                    $l = $settings[$key];
                    $this->stdout(sprintf("  %-30s %s  back %ds  window %ds  page %d\n", $dataset, $l['enabled'] ? 'enabled ' : 'DISABLED', $l['notOlderThan'], $l['maxDuration'], $l['maxPageSize']),
                        $l['enabled'] ? Console::FG_GREEN : Console::FG_RED);
                }
                $missing = array_filter(['clientIP', 'clientRequestPath', 'edgeResponseStatus', 'userAgent', 'clientAsn', 'clientCountryName', 'clientRequestHTTPMethodName'],
                    static fn(string $f) => !CloudflareTraffic::hasField($settings['http'], $f));
                $this->stdout($missing ? '  Missing fields: ' . implode(', ', $missing) . "\n" : "  All required fields available.\n", $missing ? Console::FG_RED : Console::FG_GREEN);
                $this->stdout('  Query strings (attack payloads): ' . (CloudflareTraffic::hasField($settings['http'], 'clientRequestQuery') ? 'yes' : 'not on this plan') . "\n");
                if ($settings['warning']) {
                    $this->stdout('  ' . $settings['warning'] . "\n", Console::FG_YELLOW);
                }
            } catch (RuntimeException $e) {
                $this->stderr('  ' . $e->getMessage() . "\n", Console::FG_RED);
            }
        }
        $this->stdout(sprintf("Settings: open findings from score %d, blocks for %d days, flood at %d requests a minute.\n", EdgeSettings::minScore(), EdgeSettings::blockDays(), EdgeSettings::volumeRpm()));
        $this->stdout(sprintf("Findings: %d to triage (%d high priority).\n",
            EdgeFinding::find()->where(['status' => EdgeFinding::STATUS_NEW])->count(),
            EdgeFinding::find()->where(['status' => EdgeFinding::STATUS_NEW, 'priority' => 'high'])->count()));
        foreach (EdgePull::recent() as $pull) {
            $this->stdout(sprintf("  %s  %-7s %s\n", $pull->started_at, $pull->status, $pull->message ?? sprintf('%d new, %d updated', $pull->findings_new, $pull->findings_updated)),
                $pull->getIsError() ? Console::FG_RED : Console::FG_GREEN);
        }
        return ExitCode::OK;
    }
}
