<?php

namespace app\models;

use app\components\Actor;
use app\modules\inventory\components\DevicePosture;
use yii\db\Query;

/**
 * Manual check results (pass / fail) recorded on a cloud account, cloud asset
 * or supplier, edited through the record's form like Device::$checks. The using
 * class declares `public $checks = null;` and implements checkTable(),
 * checkColumn() and manualChecks().
 */
trait RecordsChecks
{
    /** @var array<int,string>|null recorded results (cache) */
    private ?array $checkResults = null;

    /** Results table, e.g. cloud_asset_control */
    abstract public static function checkTable(): string;

    /** Foreign key column in the results table, e.g. cloud_asset_id */
    abstract public static function checkColumn(): string;

    /** @return array<int,object> manual checks (control id => check) that can be recorded on this kind of record */
    abstract public static function manualChecks(): array;

    /** @return array<int,string> recorded manual check results: control id => pass | fail */
    public function getCheckResults(): array
    {
        if ($this->checkResults === null) {
            $this->checkResults = $this->isNewRecord ? [] : array_map('strval', (new Query())->select(['result', 'control_id'])->from(static::checkTable())
                ->where([static::checkColumn() => $this->id])->indexBy('control_id')->column(static::getDb()));
        }
        return $this->checkResults;
    }

    /** Only manual checks in force, with a pass / fail / empty (not assessed) result */
    public function validateChecks(string $attribute): void
    {
        if (!is_array($this->checks)) {
            $this->checks = [];
            return;
        }
        $manual = static::manualChecks();
        foreach ($this->checks as $id => $result) {
            if (!isset($manual[(int) $id]) || !in_array($result, ['', DevicePosture::RESULT_PASS, DevicePosture::RESULT_FAIL], true)) {
                $this->addError($attribute, 'Invalid check result.');
                return;
            }
        }
    }

    /** Form pre-fill: links plus the check selects */
    public function populateLinks(): void
    {
        parent::populateLinks();
        if ($this->checks === null) {
            $results = $this->getCheckResults();
            $this->checks = [];
            foreach (array_keys(static::manualChecks()) as $id) {
                $this->checks[$id] = $results[$id] ?? '';
            }
        }
    }

    public function afterSave($insert, $changedAttributes): void
    {
        if (is_array($this->checks)) {
            $this->syncChecks($this->checks);
        }
        parent::afterSave($insert, $changedAttributes);
    }

    /** @param array<int,string> $checks control id => pass | fail | '' (clears the result) */
    private function syncChecks(array $checks): void
    {
        $db = static::getDb();
        $table = static::checkTable();
        $column = static::checkColumn();
        $current = $this->getCheckResults();
        foreach ($checks as $id => $result) {
            $id = (int) $id;
            $old = $current[$id] ?? '';
            if ($result === $old) {
                continue;
            }
            if ($result === '') {
                $db->createCommand()->delete($table, [$column => $this->id, 'control_id' => $id])->execute();
            } elseif ($old === '') {
                $db->createCommand()->insert($table, [$column => $this->id, 'control_id' => $id, 'result' => $result,
                    'checked_on' => Actor::today(), 'checked_by' => Actor::id()])->execute();
            } else {
                $db->createCommand()->update($table, ['result' => $result, 'checked_on' => Actor::today(), 'checked_by' => Actor::id()],
                    [$column => $this->id, 'control_id' => $id])->execute();
            }
        }
        $this->checkResults = null;
    }

    /** Audit snapshot of the recorded check results, e.g. "CTL-0021 Root MFA: Pass" */
    protected function auditLinkCallbacks(): array
    {
        return parent::auditLinkCallbacks() + [
            'checks' => static function (self $model) {
                if ($model->isNewRecord || !$model->id) {
                    return [];
                }
                $rows = (new Query())->select(['c.reference', 'c.title', 'r.result'])->from(['r' => static::checkTable()])
                    ->innerJoin(['c' => 'control'], 'c.id = r.control_id')->where(['r.' . static::checkColumn() => $model->id])
                    ->orderBy('c.reference')->all(static::getDb());
                return array_map(static fn($r) => $r['reference'] . ' ' . $r['title'] . ': ' . (DevicePosture::resultLabels()[$r['result']] ?? $r['result']), $rows);
            },
        ];
    }
}
