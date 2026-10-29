<?php

namespace app\modules\intel\components\edge;

use app\components\Actor;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\Investigator;
use app\modules\intel\models\BlocklistEntry;
use app\modules\intel\models\EdgeFinding;
use app\modules\intel\models\EdgePull;
use app\modules\intel\models\IntelIndicator;
use RuntimeException;
use Yii;
use yii\mutex\FileMutex;

/**
 * One pull: reads the Cloudflare traffic since the last pull, profiles and
 * scores every client address, and opens or updates edge findings.
 *
 *   - The window starts where the last successful pull ended (the first pull
 *     looks back FIRST_LOOKBACK), ends LAG seconds ago (analytics arrive late),
 *     and is cut to what the plan allows (maxDuration, notOlderThan).
 *   - Addresses Cloudflare already stopped (blocked / challenged) are skipped,
 *     as are allowlisted ones and scores below the minimum (Admin › Settings).
 *   - A dismissed or blocked finding reopens when its address comes back with
 *     a high enough score; ignored ones are only counted.
 *   - The highest new findings are investigated with the intel sources
 *     (ENRICH_LIMIT a pull), which can raise their priority.
 *   - Expired blocklist entries are taken out of the Cloudflare rule.
 */
final class EdgePuller
{
    public const MUTEX = 'edge-pull';
    public const LAG = 120;
    public const FIRST_LOOKBACK = 3600;
    public const ENRICH_LIMIT = 10;

    /** @var int|null window override in minutes (console --minutes) */
    public ?int $minutes = null;
    public bool $dryRun = false;
    public bool $enrich = true;
    /** Current time; tests set it */
    public ?int $now = null;

    /** @var array<string, string> ip => created | updated | reopened, for the console output */
    public array $changes = [];

    /**
     * @throws RuntimeException when another pull is running
     */
    public function run(): EdgePull
    {
        $mutex = new FileMutex();
        if (!$mutex->acquire(self::MUTEX)) {
            throw new RuntimeException('Another edge pull is running.');
        }
        try {
            return $this->pull();
        } finally {
            $mutex->release(self::MUTEX);
        }
    }

    private function pull(): EdgePull
    {
        $pull = new EdgePull(['started_at' => Actor::now(), 'status' => EdgePull::STATUS_ERROR, 'run_by' => Actor::id()]);
        $warnings = [];
        try {
            if (!CloudflareTraffic::isConfigured()) {
                throw new RuntimeException('Cloudflare is not configured: set CLOUDFLARE_API_TOKEN (Zone > Analytics > Read) and CLOUDFLARE_ZONE_ID in .env.');
            }
            $settings = CloudflareTraffic::settings();
            if ($settings['warning']) {
                $warnings[] = $settings['warning'];
            }
            [$start, $end, $note] = $this->window($settings['http']);
            if ($note) {
                $warnings[] = $note;
            }
            $pull->window_start = date('Y-m-d H:i:s', $start);
            $pull->window_end = date('Y-m-d H:i:s', $end);
            if ($end - $start < 60) {
                $pull->status = EdgePull::STATUS_OK;
                $pull->message = 'Nothing new to read yet (the last pull was less than a minute ago).';
                return $this->finish($pull);
            }

            $traffic = CloudflareTraffic::fetch($start, $end, $settings);
            $warnings = array_merge($warnings, $traffic['warnings']);
            if ($traffic['truncated']) {
                $warnings[] = sprintf('Page limit reached (%s): the busiest rows were read, quieter addresses may be missing. Pull more often to keep windows small.', implode(', ', $traffic['truncated']));
            }
            $minutes = max(1, (int) round(($end - $start) / 60));
            $profiles = EdgeAnalyzer::profiles($traffic, $minutes, EdgeSettings::ignoredPaths(), EdgeSettings::allowlist());
            $pull->ips = count($profiles);
            $pull->requests = array_sum(array_column($profiles, 'requests'));

            $tx = Yii::$app->db->beginTransaction();
            try {
                $this->apply($profiles, $pull, date('Y-m-d H:i:s', $end));
                $this->dryRun ? $tx->rollBack() : $tx->commit();
            } catch (\Throwable $e) {
                $tx->rollBack();
                throw $e;
            }

            if (!$this->dryRun) {
                $expired = BlocklistEntry::removeExpiredFromCloudflare();
                if ($expired['removed']) {
                    $warnings[] = 'Expired blocks taken out of Cloudflare: ' . implode(', ', $expired['removed']) . '.';
                }
                foreach ($expired['failed'] as $ref => $error) {
                    $warnings[] = "$ref could not be taken out of Cloudflare: $error";
                }
            }
            $pull->status = $warnings ? EdgePull::STATUS_PARTIAL : EdgePull::STATUS_OK;
            $pull->message = $warnings ? mb_substr(implode(' ', $warnings), 0, 500) : null;
        } catch (\Exception $e) {
            // Cloudflare and database failures alike end the pull as an error that the page shows
            if (!$e instanceof RuntimeException) {
                Yii::error('Edge pull failed: ' . $e, __METHOD__);
            }
            $pull->status = EdgePull::STATUS_ERROR;
            $pull->message = mb_substr($e->getMessage(), 0, 500);
        }
        return $this->finish($pull);
    }

    private function finish(EdgePull $pull): EdgePull
    {
        $pull->finished_at = Actor::now();
        if (!$this->dryRun) {
            $pull->save(false);
        }
        return $pull;
    }

    /**
     * @return array{int, int, ?string} window start and end (unix time), and a note when it was cut short
     */
    public function window(array $limits): array
    {
        $now = $this->now ?? time();
        $end = intdiv($now - self::LAG, 60) * 60;
        $last = EdgePull::lastSuccessful();
        $start = match (true) {
            $this->minutes !== null => $end - $this->minutes * 60,
            $last !== null => strtotime($last->window_end),
            default => $end - self::FIRST_LOOKBACK,
        };
        $start = min($start, $end);
        $note = null;
        $earliest = $now - $limits['notOlderThan'] + 60;
        if ($start < $earliest) {
            $note = sprintf('Cloudflare keeps %s of analytics on this plan, so the window starts %s.', self::duration($limits['notOlderThan']), date('Y-m-d H:i', $earliest));
            $start = $earliest;
        }
        if ($end - $start > $limits['maxDuration']) {
            $start = $end - $limits['maxDuration'];
            $note = sprintf('A query can cover at most %1$s on this plan, so only the most recent %1$s was read. Pull more often (every 15 minutes) to avoid gaps.', self::duration($limits['maxDuration']));
        }
        return [$start, max($start, $end), $note];
    }

    private static function duration(int $seconds): string
    {
        return $seconds % 86400 === 0 ? sprintf('%d day%s', $seconds / 86400, $seconds === 86400 ? '' : 's')
            : ($seconds % 3600 === 0 ? sprintf('%d hour%s', $seconds / 3600, $seconds === 3600 ? '' : 's') : sprintf('%d minutes', intdiv($seconds, 60)));
    }

    /** Opens / updates findings for the scored profiles */
    public function apply(array $profiles, EdgePull $pull, string $seenAt): void
    {
        $minScore = EdgeSettings::minScore();
        $context = ['volume_rpm' => EdgeSettings::volumeRpm()];
        $existing = $profiles ? EdgeFinding::find()->where(['ip' => array_keys($profiles)])->indexBy('ip')->all() : [];
        $toEnrich = [];

        foreach ($profiles as $ip => $profile) {
            /** @var EdgeFinding|null $finding */
            $finding = $existing[$ip] ?? null;
            $merged = $finding ? EdgeAnalyzer::merge($finding->getProfile(), $profile) : $profile;
            $block = BlocklistEntry::findForIp($ip, $merged['asn']);
            $inForce = array_values(array_filter($block, static fn(BlocklistEntry $b) => $b->getIsInForce()));
            $ctx = $context + ['block' => $inForce ? ['reference' => $inForce[0]->reference, 'cloudflare' => $inForce[0]->cloudflare_added_at !== null] : null];
            $fresh = EdgeAnalyzer::score($profile, $ctx);

            if ($finding === null) {
                if ($fresh['score'] < $minScore || (EdgeAnalyzer::isHandled($profile) && !$fresh['exposure'])) {
                    continue;
                }
                $finding = new EdgeFinding(['ip' => $ip, 'status' => EdgeFinding::STATUS_NEW, 'first_seen_at' => $seenAt, 'times_seen' => 0]);
                $this->changes[$ip] = 'created';
                $pull->findings_new++;
            } else {
                $reopen = in_array($finding->status, EdgeFinding::REOPENABLE, true) && $fresh['score'] >= $minScore && !EdgeAnalyzer::isHandled($profile);
                if ($reopen) {
                    $finding->status = EdgeFinding::STATUS_NEW;
                    $finding->resolved_at = null;
                    $finding->resolved_by = null;
                    $finding->note = mb_substr(sprintf('Seen again %s after being %s.', $seenAt, $existing[$ip]->getOldAttribute('status')), 0, 500);
                }
                $this->changes[$ip] = $reopen ? 'reopened' : 'updated';
                $pull->findings_updated++;
            }

            $scored = EdgeAnalyzer::score($merged, $ctx);
            $finding->setProfile($merged);
            $finding->asn = $merged['asn'];
            $finding->country = $merged['country'];
            $finding->score = $scored['score'];
            $finding->signals = json_encode($scored['signals'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $finding->headline = $scored['headline'];
            $finding->exposure = $scored['exposure'] ? 1 : 0;
            $finding->requests = $merged['requests'];
            $finding->stopped = $merged['stopped'];
            $finding->error_paths = $scored['error_paths'];
            $finding->times_seen = (int) $finding->times_seen + 1;
            $finding->last_seen_at = $seenAt;
            $finding->priority = EdgeAnalyzer::priority($scored['score'], $scored['exposure'], $finding->intel_rating);
            if (!$finding->save()) {
                throw new RuntimeException(sprintf('Finding for %s not saved: %s', $ip, implode(' ', $finding->getErrorSummary(true))));
            }
            if ($finding->status === EdgeFinding::STATUS_NEW && ($finding->intel_checked_at === null || strtotime($finding->intel_checked_at) < strtotime($seenAt) - 86400)) {
                $toEnrich[] = $finding;
            }
        }

        if ($this->enrich) {
            usort($toEnrich, static fn(EdgeFinding $a, EdgeFinding $b) => $b->score <=> $a->score);
            foreach (array_slice($toEnrich, 0, self::ENRICH_LIMIT) as $finding) {
                self::enrich($finding);
            }
        }
    }

    /** Investigates the address with the intel sources and records the rating on the finding */
    public static function enrich(EdgeFinding $finding): void
    {
        $indicator = Indicator::ip($finding->ip);
        if ($indicator === null) {
            return;
        }
        $report = Investigator::run($indicator);
        $intel = IntelIndicator::recordLookup($report);
        $finding->intel_score = $intel->last_score;
        $finding->intel_rating = $intel->last_rating;
        $finding->intel_checked_at = Actor::now();
        $finding->priority = EdgeAnalyzer::priority((int) $finding->score, (bool) $finding->exposure, $finding->intel_rating);
        $finding->save(false);
    }
}
