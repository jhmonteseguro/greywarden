<?php

namespace app\commands;

use app\components\Actor;
use app\models\User;
use app\modules\inventory\components\DeviceImporter;
use app\modules\inventory\components\GoogleDeviceCsv;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Device inventory imports.
 *
 *   php yii device/import devices.csv --user=jdoe       # Google Workspace device export
 *   php yii device/import - --user=jdoe < devices.csv   # read the CSV from stdin
 *   php yii device/import devices.csv --dry-run=1       # validate and report, change nothing
 *
 * Re-importing a newer export updates the same devices (matched on the Google device ID).
 */
class DeviceController extends Controller
{
    public $defaultAction = 'import';

    /** @var string|null username recorded as the creator / in the audit trail */
    public $user;
    /** @var bool roll everything back after reporting */
    public $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'import' ? ['user', 'dryRun'] : []);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['n' => 'dryRun']);
    }

    public function actionImport(string $file): int
    {
        $csv = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
        if ($csv === false || $csv === '') {
            $this->stderr("Cannot read {$file}.\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }
        try {
            $records = GoogleDeviceCsv::parse($csv);
        } catch (\InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $actor = null;
        if ($this->user !== null) {
            $actor = User::findByUsername($this->user);
            if (!$actor) {
                $this->stderr("User '{$this->user}' not found.\n", Console::FG_RED);
                return ExitCode::DATAERR;
            }
        }
        Actor::override($actor?->id, $actor?->username);

        $note = sprintf('Imported from a Google Workspace device export on %s.', date('Y-m-d'));
        $tx = Yii::$app->db->beginTransaction();
        try {
            $results = (new DeviceImporter())->import($records, $note);
            $this->dryRun ? $tx->rollBack() : $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            Actor::override(null);
            $this->stderr('Import failed: ' . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::SOFTWARE;
        }
        Actor::override(null);

        $colour = [DeviceImporter::CREATED => Console::FG_GREEN, DeviceImporter::UPDATED => Console::FG_CYAN, DeviceImporter::FAILED => Console::FG_RED];
        $counts = array_fill_keys([DeviceImporter::CREATED, DeviceImporter::UPDATED, DeviceImporter::UNCHANGED, DeviceImporter::FAILED], 0);
        foreach ($results as $r) {
            $counts[$r['action']]++;
            $d = $r['device'];
            $detail = match ($r['action']) {
                DeviceImporter::FAILED => implode('; ', $r['errors']),
                DeviceImporter::UPDATED => 'changed: ' . implode(', ', $r['changed']),
                default => trim(implode(' · ', array_filter([$d->getTypeLabel(), trim($d->os . ' ' . $d->os_version), $d->assigned_to]))),
            };
            $this->stdout(sprintf("  line %-3d %-9s %-9s %-40s %s\n", $r['line'], $r['action'], $d->asset_tag ?? '-', mb_strimwidth($d->name, 0, 40, '…'), $detail), $colour[$r['action']] ?? null);
        }
        if (!$this->dryRun) {
            Yii::$app->cache->flush();
        }
        $this->stdout(sprintf("%s%d created, %d updated, %d unchanged, %d failed.\n", $this->dryRun ? 'Dry run (nothing saved): ' : '',
            $counts[DeviceImporter::CREATED], $counts[DeviceImporter::UPDATED], $counts[DeviceImporter::UNCHANGED], $counts[DeviceImporter::FAILED]),
            $counts[DeviceImporter::FAILED] ? Console::FG_YELLOW : Console::FG_GREEN);
        return $counts[DeviceImporter::FAILED] ? ExitCode::DATAERR : ExitCode::OK;
    }
}
