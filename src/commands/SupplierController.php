<?php

namespace app\commands;

use app\components\Actor;
use app\models\User;
use app\modules\supplier\components\CloudSuppliers;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Supplier Register maintenance.
 *
 *   yii supplier/from-cloud --user=jdoe [--dry-run=1]
 *       One supplier per vendor of the cloud accounts on record (AWS, Microsoft,
 *       Google, Cloudflare, GitHub), owned by --user. Accounts are not linked.
 */
class SupplierController extends Controller
{
    public $defaultAction = 'from-cloud';

    /** @var string|null username or email of the owner, recorded as the creator / in the audit trail */
    public $user;
    /** @var bool roll everything back after reporting */
    public $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['user', 'dryRun']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['n' => 'dryRun']);
    }

    public function actionFromCloud(): int
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
        $tx = Yii::$app->db->beginTransaction();
        try {
            $results = CloudSuppliers::create($actor?->id);
            $this->dryRun ? $tx->rollBack() : $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            Actor::override(null);
            $this->stderr('Creating suppliers failed: ' . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::SOFTWARE;
        }
        Actor::override(null);

        $colour = [CloudSuppliers::CREATED => Console::FG_GREEN, CloudSuppliers::FAILED => Console::FG_RED];
        $counts = array_fill_keys([CloudSuppliers::CREATED, CloudSuppliers::EXISTS, CloudSuppliers::FAILED], 0);
        foreach ($results as $r) {
            $counts[$r['action']]++;
            $s = $r['supplier'];
            $detail = $r['action'] === CloudSuppliers::FAILED
                ? implode('; ', $r['errors'])
                : sprintf('%s · %d account(s) · Tier %d', implode(', ', $r['providers']), $r['accounts'], $s->tier);
            $this->stdout(sprintf("  %-8s %-9s %-24s %s\n", $r['action'], $s->reference ?? '-', mb_strimwidth($s->name, 0, 24, '…'), $detail),
                $colour[$r['action']] ?? null);
        }
        if (!$results) {
            $this->stdout("No cloud accounts of a known provider: nothing to create.\n");
        }
        $this->stdout(sprintf("%s%d created, %d already in the register, %d failed.\n", $this->dryRun ? 'Dry run (nothing saved): ' : '',
            $counts[CloudSuppliers::CREATED], $counts[CloudSuppliers::EXISTS], $counts[CloudSuppliers::FAILED]),
            $counts[CloudSuppliers::FAILED] ? Console::FG_RED : Console::FG_GREEN);
        if ($counts[CloudSuppliers::CREATED] && !$this->dryRun) {
            $this->stdout("Link each cloud account to its supplier on the account's edit form, then fill in the contract terms and assurance.\n", Console::FG_YELLOW);
        }
        return $counts[CloudSuppliers::FAILED] ? ExitCode::DATAERR : ExitCode::OK;
    }
}
