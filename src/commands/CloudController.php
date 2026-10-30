<?php

namespace app\commands;

use app\components\Actor;
use app\models\User;
use app\modules\cloud\components\CloudCatalogue;
use app\modules\cloud\components\CloudExport;
use app\modules\cloud\components\CloudflareZones;
use app\modules\cloud\components\CloudImporter;
use app\modules\cloud\models\CloudAccount;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Cloud Inventory imports. Greywarden never holds cloud credentials: run the
 * provider's CLI yourself and import its output (see README, "Importing cloud
 * assets").
 *
 *   php yii cloud/import resources.json --provider=aws --user=jdoe   # Resource Explorer or AWS Config export
 *   php yii cloud/import - --provider=azure < resources.csv          # read the export from stdin
 *   php yii cloud/import assets.json --provider=gcp --dry-run=1      # validate and report, change nothing
 *   php yii cloud/import ids.csv --provider=aws --account=ACC-0003   # put every asset in one existing account
 *   php yii cloud/sync-cloudflare --user=jdoe                        # Cloudflare zones, with CLOUDFLARE_API_TOKEN
 *
 * Re-importing a newer export updates the same assets (matched on the
 * provider's resource ID). Resource types the inventory does not track are
 * skipped and summarised.
 */
class CloudController extends Controller
{
    public $defaultAction = 'import';

    /** @var string|null aws | azure | gcp */
    public $provider;
    /** @var string|null reference of an existing account (ACC-0003) that gets every asset */
    public $account;
    /** @var string|null username or email (as at sign-in) of the user recorded as the creator / in the audit trail */
    public $user;
    /** @var bool roll everything back after reporting */
    public $dryRun = false;

    /** Unmapped types listed by name in the summary; the rest are counted */
    private const SKIPPED_LISTED = 10;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'import' => ['provider', 'account', 'user', 'dryRun'],
            'sync-cloudflare' => ['user', 'dryRun'],
            default => [],
        });
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['n' => 'dryRun', 'p' => 'provider']);
    }

    public function actionImport(string $file): int
    {
        if (!in_array($this->provider, CloudExport::providers(), true)) {
            $this->stderr("Choose the export's provider: --provider=aws, azure or gcp.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $content = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
        if ($content === false || $content === '') {
            $this->stderr("Cannot read {$file}.\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }
        try {
            $records = CloudExport::for($this->provider)->parse($content);
        } catch (\InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $account = null;
        if ($this->account !== null) {
            $account = CloudAccount::findOne(['reference' => strtoupper(trim($this->account))]);
            if ($account === null || $account->provider !== $this->provider) {
                $this->stderr(sprintf("%s is not an account of %s.\n", $this->account, CloudCatalogue::providerLabel($this->provider)), Console::FG_RED);
                return ExitCode::DATAERR;
            }
        }
        $note = sprintf('Imported from a %s export on %s.', CloudCatalogue::providerLabel($this->provider), date('Y-m-d'));
        return $this->importRecords($records, $note, $account);
    }

    public function actionSyncCloudflare(): int
    {
        try {
            $records = CloudflareZones::records();
        } catch (\RuntimeException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }
        return $this->importRecords($records, sprintf('Synced from Cloudflare on %s.', date('Y-m-d')), null);
    }

    /** Imports the records as --user (in one transaction, rolled back on a dry run) and prints the results */
    private function importRecords(array $records, string $note, ?CloudAccount $account): int
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

        $importer = new CloudImporter(date('Y-m-d H:i:s'), $account);
        $tx = Yii::$app->db->beginTransaction();
        try {
            $results = $importer->import($records, $note);
            $this->dryRun ? $tx->rollBack() : $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            Actor::override(null);
            $this->stderr('Import failed: ' . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::SOFTWARE;
        }
        Actor::override(null);

        $colour = [CloudImporter::CREATED => Console::FG_GREEN, CloudImporter::UPDATED => Console::FG_CYAN, CloudImporter::FAILED => Console::FG_RED];
        $counts = array_fill_keys([CloudImporter::CREATED, CloudImporter::UPDATED, CloudImporter::UNCHANGED, CloudImporter::SKIPPED, CloudImporter::FAILED], 0);
        foreach ($results as $r) {
            $counts[$r['action']]++;
            if ($r['action'] === CloudImporter::SKIPPED) {
                continue;
            }
            // A row without an asset holds the account's own settings
            $a = $r['asset'] ?? $r['account'];
            $detail = match ($r['action']) {
                CloudImporter::FAILED => implode('; ', $r['errors']),
                CloudImporter::UPDATED => 'changed: ' . implode(', ', $r['changed']),
                default => $r['asset'] ? trim(implode(' · ', array_filter([$a->getKindLabel(), $a->region, $a->account->name ?? null]))) : 'account settings',
            };
            if (($a?->finding_count ?? 0) > 0) {
                $detail .= sprintf(' [%d finding(s), worst %s]', $a->finding_count, $a->finding_severity);
            }
            if ($r['state'] !== null) {
                $detail .= " (state: {$r['state']})";
            }
            $this->stdout(sprintf("  row %-4d %-9s %-9s %-40s %s\n", $r['line'], $r['action'], $a->reference ?? '-',
                mb_strimwidth($a->name ?? $r['type'], 0, 40, '…'), $detail), $colour[$r['action']] ?? null);
        }
        foreach ($importer->createdAccounts as $acc) {
            $this->stdout(sprintf("New account %s %s (%s %s): set its environment (now %s), owner, criticality and data classification.\n",
                $acc->reference ?? '-', $acc->name, CloudCatalogue::identifierLabel($acc->provider), $acc->account_identifier,
                CloudAccount::environmentOptions()[$acc->environment]), Console::FG_YELLOW);
        }
        if ($importer->skippedTypes) {
            arsort($importer->skippedTypes);
            $listed = array_slice($importer->skippedTypes, 0, self::SKIPPED_LISTED, true);
            $more = count($importer->skippedTypes) - count($listed);
            $this->stdout(sprintf("Skipped %d row(s) of %d resource type(s) the inventory does not track: %s%s.\n", $counts[CloudImporter::SKIPPED],
                count($importer->skippedTypes), implode(', ', array_map(static fn($t, $n) => "$t ($n)", array_keys($listed), $listed)),
                $more ? sprintf(' and %d more', $more) : ''));
        }
        if (!$this->dryRun) {
            Yii::$app->cache->flush();
        }
        $this->stdout(sprintf("%s%d created, %d updated, %d unchanged, %d skipped, %d failed.\n", $this->dryRun ? 'Dry run (nothing saved): ' : '',
            $counts[CloudImporter::CREATED], $counts[CloudImporter::UPDATED], $counts[CloudImporter::UNCHANGED], $counts[CloudImporter::SKIPPED],
            $counts[CloudImporter::FAILED]), $counts[CloudImporter::FAILED] ? Console::FG_YELLOW : Console::FG_GREEN);
        return $counts[CloudImporter::FAILED] ? ExitCode::DATAERR : ExitCode::OK;
    }
}
