<?php

namespace app\commands;

use app\modules\intel\components\Cloudflare;
use app\modules\intel\components\edge\EdgePuller;
use app\modules\intel\components\edge\EdgeSettings;
use app\modules\intel\models\BlocklistEntry;
use RuntimeException;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Greywarden's scheduled jobs, run by the `scheduler` container (docker-compose.yml),
 * so no host crontab is needed:
 *
 *   edge-pull       every EDGE_PULL_INTERVAL minutes (default 15, 0 = off): Cloudflare traffic → edge findings
 *                   (each pull also takes expired blocks out of Cloudflare)
 *   expire-blocks   hourly, only when edge pulls are off: expired blocks out of Cloudflare
 *
 * Jobs that need Cloudflare are skipped while it is not configured. Each job runs on
 * its own; a failure is logged and the loop carries on.
 *
 *   php yii schedule/run              # the loop (the container's command)
 *   php yii schedule/run --once=1     # run what is due once and exit
 *   php yii schedule/status           # the jobs, their interval and whether they are on
 */
class ScheduleController extends Controller
{
    public $defaultAction = 'status';

    /** @var bool run the due jobs once and exit */
    public $once = false;

    /** @var array<string, int> job => when it last started (unix time) */
    private array $lastRun = [];

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'run' ? ['once'] : []);
    }

    /**
     * @return array<string, array{interval: int, off: ?string, run: callable}> job => interval in minutes, why it is off (null = on), what it does
     */
    public function jobs(): array
    {
        $edge = EdgeSettings::pullInterval();
        $cloudflare = Cloudflare::isConfigured() ? null : 'Cloudflare is not configured';
        return [
            'edge-pull' => ['interval' => $edge, 'off' => $cloudflare ?? ($edge === 0 ? 'EDGE_PULL_INTERVAL=0' : null), 'run' => fn() => $this->edgePull()],
            'expire-blocks' => ['interval' => 60, 'off' => $cloudflare ?? ($edge > 0 ? 'every edge pull does it' : null), 'run' => fn() => $this->expireBlocks()],
        ];
    }

    public function actionRun(): int
    {
        $this->log('Scheduler started. ' . $this->summary());
        while (true) {
            $this->tick(time());
            if ($this->once) {
                return ExitCode::OK;
            }
            // Wake at the start of the next minute
            sleep(60 - time() % 60);
        }
    }

    public function actionStatus(): int
    {
        $this->stdout($this->summary() . "\n");
        return ExitCode::OK;
    }

    /**
     * Runs the jobs that are due at $now.
     * @return string[] the jobs that ran
     */
    public function tick(int $now): array
    {
        $ran = [];
        foreach ($this->jobs() as $name => $job) {
            if ($job['off'] !== null) {
                continue;
            }
            // 30 seconds' slack, so a tick that wakes a little late still counts
            if (isset($this->lastRun[$name]) && $now - $this->lastRun[$name] < $job['interval'] * 60 - 30) {
                continue;
            }
            $this->lastRun[$name] = $now;
            $ran[] = $name;
            try {
                ($job['run'])();
            } catch (\Throwable $e) {
                Yii::error("Scheduled job $name failed: $e", __METHOD__);
                $this->log("$name failed: " . $e->getMessage());
            } finally {
                Yii::getLogger()->flush(true);
                if (!$this->once) {
                    // A long-running process: reconnect next time rather than keep an idle connection
                    Yii::$app->db->close();
                }
            }
        }
        return $ran;
    }

    private function edgePull(): void
    {
        try {
            $pull = (new EdgePuller())->run();
        } catch (RuntimeException $e) {
            $this->log('edge-pull skipped: ' . $e->getMessage());
            return;
        }
        $this->log($pull->getIsError()
            ? 'edge-pull failed: ' . $pull->message
            : trim(sprintf('edge-pull %s → %s: %d requests from %d addresses, %d new, %d updated. %s',
                $pull->window_start, $pull->window_end, $pull->requests, $pull->ips, $pull->findings_new, $pull->findings_updated, (string) $pull->message)));
    }

    private function expireBlocks(): void
    {
        $result = BlocklistEntry::removeExpiredFromCloudflare();
        if ($result['removed']) {
            $this->log('expire-blocks: taken out of Cloudflare: ' . implode(', ', $result['removed']));
        }
        foreach ($result['failed'] as $ref => $error) {
            $this->log("expire-blocks: $ref: $error");
        }
    }

    private function summary(): string
    {
        $parts = [];
        foreach ($this->jobs() as $name => $job) {
            $parts[] = $job['off'] !== null ? "$name off ({$job['off']})" : sprintf('%s every %d min', $name, $job['interval']);
        }
        return 'Jobs: ' . implode('; ', $parts) . '.';
    }

    private function log(string $line): void
    {
        $this->stdout(sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $line));
    }
}
