<?php

namespace app\modules\cloud\models;

use app\models\DomainQuery;
use app\modules\cloud\components\CloudCheck;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\components\CloudConfig;
use app\modules\cloud\components\CloudPosture;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\components\OsLifecycle;
use yii\db\Expression;
use yii\db\Query;

class CloudAssetQuery extends DomainQuery
{
    public function inService(): static
    {
        return $this->andWhere(['cloud_asset.status' => CloudAsset::IN_SERVICE_STATUSES]);
    }

    public function internetFacing(): static
    {
        return $this->andWhere(['cloud_asset.internet_facing' => 1]);
    }

    /** Assets whose imported configuration raised findings, optionally only those at least as severe as $severity */
    public function withFindings(?string $severity = null): static
    {
        $this->andWhere(['>', 'cloud_asset.finding_count', 0]);
        return $severity ? $this->andWhere(['cloud_asset.finding_severity' => CloudConfig::atLeast($severity) ?: '']) : $this;
    }

    // ---- Posture: SQL mirror of CloudPosture::evaluateAsset() ------------------------

    /** Every applicable check passes */
    public function compliantCondition(?string $today = null): array|string
    {
        $cond = ['and'];
        foreach (CloudChecks::forAssets() as $check) {
            $applies = $this->appliesCondition($check);
            $passes = $this->resultCondition($check, CloudPosture::RESULT_PASS, $today);
            $cond[] = $applies ? ['or', ['not', $applies], $passes] : $passes;
        }
        return count($cond) > 1 ? $cond : '1=1';
    }

    /** Some applicable check fails */
    public function nonCompliantCondition(?string $today = null): array|string
    {
        $cond = ['or'];
        foreach (CloudChecks::forAssets() as $check) {
            $cond[] = $this->checkCondition($check, CloudPosture::RESULT_FAIL, $today);
        }
        return count($cond) > 1 ? $cond : '0=1';
    }

    public function compliant(?string $today = null): static
    {
        return $this->andWhere($this->compliantCondition($today));
    }

    public function nonCompliant(?string $today = null): static
    {
        return $this->andWhere($this->nonCompliantCondition($today));
    }

    /** No check fails, but at least one has no result */
    public function notAssessed(?string $today = null): static
    {
        return $this->andWhere(['not', $this->nonCompliantCondition($today)])
            ->andWhere(['not', $this->compliantCondition($today)]);
    }

    /** Assets the check applies to whose result is pass | fail | unknown */
    public function checkResult(CloudCheck $check, string $result, ?string $today = null): static
    {
        return $this->andWhere($check->assetTypes() === [] ? '0=1' : $this->checkCondition($check, $result, $today));
    }

    private function checkCondition(CloudCheck $check, string $result, ?string $today): array
    {
        $applies = $this->appliesCondition($check);
        $is = $this->resultCondition($check, $result, $today);
        return $applies ? ['and', $applies, $is] : $is;
    }

    /** Resource type scope of a check ([] = every asset) */
    private function appliesCondition(CloudCheck $check): array
    {
        $types = $check->assetTypes();
        return $types === null ? [] : ['cloud_asset.resource_type' => $types];
    }

    private function resultCondition(CloudCheck $check, string $result, ?string $today): array
    {
        if ($check->isOsSupport()) {
            return match ($result) {
                CloudPosture::RESULT_PASS => $this->osSupportCondition([OsLifecycle::SUPPORTED, OsLifecycle::ENDING], $today),
                CloudPosture::RESULT_FAIL => $this->osSupportCondition(OsLifecycle::UNSUPPORTED, $today),
                default => $this->osSupportCondition(OsLifecycle::UNKNOWN, $today),
            };
        }
        if ($check->isEncryption()) {
            return match ($result) {
                CloudPosture::RESULT_PASS => ['cloud_asset.encryption_at_rest' => CloudAsset::ENCRYPTION_YES],
                CloudPosture::RESULT_FAIL => ['cloud_asset.encryption_at_rest' => CloudAsset::ENCRYPTION_NO],
                default => ['not in', 'cloud_asset.encryption_at_rest', [CloudAsset::ENCRYPTION_YES, CloudAsset::ENCRYPTION_NO]],
            };
        }
        $recorded = (new Query())->select(new Expression('1'))->from(['r' => 'cloud_asset_control'])
            ->where('r.cloud_asset_id = cloud_asset.id')->andWhere(['r.control_id' => $check->id]);
        return $result === CloudPosture::RESULT_UNKNOWN
            ? ['not exists', $recorded]
            : ['exists', $recorded->andWhere(['r.result' => $result])];
    }

    // ---- Lifecycle ------------------------------------------------------------

    /** Synced assets whose last sync is older than CloudAsset::staleDays() (mirror of CloudAsset::isStale()) */
    public function stale(?string $today = null): static
    {
        return $this->andWhere(['<', 'cloud_asset.last_seen_at', DevicePosture::patchCutoff(CloudAsset::staleDays(), $today)]);
    }

    /** Synced assets whose last sync is older than CloudAsset::staleCriticalDays() (mirror of CloudAsset::isMissing()) */
    public function missing(?string $today = null): static
    {
        return $this->andWhere(['<', 'cloud_asset.last_seen_at', DevicePosture::patchCutoff(CloudAsset::staleCriticalDays(), $today)]);
    }

    /**
     * Instances whose OS is in the given OsLifecycle state(s), matched in PHP over the distinct
     * os / version pairs (see DeviceQuery::osSupport()).
     *
     * @param string|string[] $states
     */
    public function osSupport(string|array $states, ?string $today = null): static
    {
        return $this->andWhere($this->osSupportCondition($states, $today));
    }

    /** Assets without an OS are unknown */
    private function osSupportCondition(string|array $states, ?string $today): array
    {
        $states = (array) $states;
        $pairs = (new Query())->select(['os', 'os_version'])->distinct()->from('cloud_asset')
            ->where(['not', ['os' => null]])->all(CloudAsset::getDb());
        $cond = ['or', in_array(OsLifecycle::UNKNOWN, $states, true) ? ['cloud_asset.os' => null] : '0=1'];
        foreach ($pairs as $p) {
            if (in_array(OsLifecycle::evaluate($p['os'], $p['os_version'], $today)['state'], $states, true)) {
                $cond[] = ['cloud_asset.os' => $p['os'], 'cloud_asset.os_version' => $p['os_version']];
            }
        }
        return $cond;
    }
}
