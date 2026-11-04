<?php

namespace app\commands;

use app\components\Actor;
use app\models\User;
use app\modules\threat\components\ThreatPuller;
use app\modules\threat\components\ThreatSettings;
use app\modules\threat\models\ThreatPull;
use app\modules\threat\models\ThreatSource;
use RuntimeException;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Threat Watch: security news and advisories from public feeds.
 *
 *   php yii threat/pull               # read the sources that are due (the scheduler container runs this every THREAT_PULL_INTERVAL minutes)
 *   php yii threat/pull --force=1     # read every enabled source now
 *   php yii threat/pull --user=jdoe   # record jdoe as the actor (default: system)
 *   php yii threat/status             # source health and recent pulls
 */
class ThreatController extends Controller
{
    public $defaultAction = 'status';

    /** @var bool read every enabled source, due or not */
    public $force = false;
    /** @var string|null username or email recorded against the pull */
    public $user;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'pull' ? ['force', 'user'] : []);
    }

    public function actionPull(): int
    {
        $actor = null;
        if ($this->user !== null) {
            $actor = User::findByLogin($this->user);
            if (!$actor) {
                $this->stderr("User '{$this->user}' not found (give a username or email).\n", Console::FG_RED);
                return ExitCode::DATAERR;
            }
        }
        Actor::override($actor?->id, $actor?->username);
        $puller = new ThreatPuller();
        $puller->force = (bool) $this->force;
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
        $this->stdout(sprintf("%d sources read, %d failed: %d new articles in %d new stories, %d CVEs enriched.\n",
            $pull->sources_read, $pull->sources_failed, $pull->articles_new, $pull->stories_new, $pull->cves_enriched), Console::FG_GREEN);
        if ($pull->message) {
            $this->stdout($pull->message . "\n", Console::FG_YELLOW);
        }
        return ExitCode::OK;
    }

    public function actionStatus(): int
    {
        $interval = ThreatSettings::pullInterval();
        $this->stdout($interval ? "The scheduler checks for sources due every $interval minutes.\n" : "Scheduled pulls are off (THREAT_PULL_INTERVAL=0).\n");
        $this->stdout(ThreatSettings::nvdApiKey() ? "NVD_API_KEY is set.\n\n" : "NVD_API_KEY is not set: CVSS scores arrive more slowly.\n\n");
        foreach (ThreatSource::find()->orderBy(['category' => SORT_ASC, 'name' => SORT_ASC])->all() as $source) {
            $this->stdout(sprintf("%-8s %-40s %6d items  %s\n", $source->getHealth(), $source->name, $source->items_total,
                $source->last_error ?? ($source->last_success_at ? 'read ' . $source->last_success_at : 'not read yet')));
        }
        $this->stdout("\nRecent pulls:\n");
        foreach (ThreatPull::recent() as $pull) {
            $this->stdout(sprintf("%s  %-7s %d read, %d failed, %d articles, %d stories\n", $pull->started_at, $pull->status, $pull->sources_read, $pull->sources_failed, $pull->articles_new, $pull->stories_new));
        }
        return ExitCode::OK;
    }
}
