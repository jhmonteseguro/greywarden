<?php

namespace app\modules\admin\models;

use app\helpers\RiskMatrix;
use app\models\AuditLog;
use app\models\Setting;
use app\modules\report\components\ReportBuilder;
use yii\base\Model;

/**
 * Admin › Settings › Management reports: the risk appetite, the highest
 * residual score the organisation accepts. Reports flag open risks above it.
 * Changes go to the audit log (entity "setting").
 */
class ReportForm extends Model
{
    public $risk_appetite;

    public static function current(): self
    {
        return new self(['risk_appetite' => ReportBuilder::appetite()]);
    }

    public function rules(): array
    {
        return [
            ['risk_appetite', 'required'],
            ['risk_appetite', 'integer', 'min' => RiskMatrix::MIN, 'max' => RiskMatrix::MAX * RiskMatrix::MAX],
        ];
    }

    public function attributeLabels(): array
    {
        return ['risk_appetite' => 'Risk appetite (highest accepted residual score)'];
    }

    public function attributeHints(): array
    {
        return ['risk_appetite' => sprintf('Open risks scoring above this are reported as outside appetite. The default, %d, accepts %s risks and below.',
            ReportBuilder::DEFAULT_APPETITE, RiskMatrix::ratingLabel(RiskMatrix::rating(ReportBuilder::DEFAULT_APPETITE)))];
    }

    /** Score => "12 · High" for the dropdown (only scores a 5x5 matrix can produce) */
    public static function appetiteOptions(): array
    {
        $options = [];
        foreach (RiskMatrix::achievableScores() as $score) {
            $options[$score] = $score . ' · ' . RiskMatrix::ratingLabel(RiskMatrix::rating($score));
        }
        return $options;
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $before = ReportBuilder::appetite();
        $value = (int) $this->risk_appetite;
        if ($value !== $before) {
            Setting::put([ReportBuilder::SETTING_APPETITE => $value === ReportBuilder::DEFAULT_APPETITE ? '' : (string) $value]);
            AuditLog::record('setting', null, 'Management reports', 'update', ['risk_appetite' => [$before, $value]]);
        }
        return true;
    }
}
