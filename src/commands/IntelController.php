<?php

namespace app\commands;

use app\modules\intel\components\FeedCatalogue;
use app\modules\intel\components\Feeds;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\Investigator;
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
 */
class IntelController extends Controller
{
    public $defaultAction = 'status';

    /** @var bool re-download all feeds, not only stale ones */
    public $force = false;
    /** @var bool bypass cached source answers (intel/lookup) */
    public $refresh = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'update' => ['force'],
            'lookup' => ['refresh'],
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
