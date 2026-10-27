<?php
/**
 * Regulator notification panel with 72h countdown.
 * @var yii\web\View $this
 * @var app\modules\incident\models\Incident $model
 */

use app\helpers\Badge;
use app\helpers\Dt;
use app\modules\incident\components\IncidentMetrics;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$state = $model->getRegulatorDeadlineState();
$deadline = $model->getRegulatorDeadline();
$labels = [
    IncidentMetrics::STATE_NOT_REQUIRED => ['muted', 'Not required'],
    IncidentMetrics::STATE_PENDING => ['pending', 'Notification due'],
    IncidentMetrics::STATE_OVERDUE => ['overdue', 'Deadline missed'],
    IncidentMetrics::STATE_MET => ['met', 'Notified in time'],
    IncidentMetrics::STATE_LATE => ['late', 'Notified late'],
];
[$flag, $label] = $labels[$state];
$icons = [
    IncidentMetrics::STATE_NOT_REQUIRED => 'dash-circle',
    IncidentMetrics::STATE_PENDING => 'hourglass-split',
    IncidentMetrics::STATE_OVERDUE => 'exclamation-octagon-fill',
    IncidentMetrics::STATE_MET => 'check-circle-fill',
    IncidentMetrics::STATE_LATE => 'exclamation-circle',
];
// Share of the notification window already used (drives the progress bar).
$windowSeconds = (int) (Yii::$app->params['incident.regulatorDeadlineHours'] ?? 72) * 3600;
$remaining = $model->getRegulatorSecondsRemaining();
$used = ($remaining === null || $windowSeconds <= 0) ? 0 : (int) round(min(1, max(0, ($windowSeconds - $remaining) / $windowSeconds)) * 100);
$live = in_array($state, [IncidentMetrics::STATE_PENDING, IncidentMetrics::STATE_OVERDUE], true);
$urgent = $state === IncidentMetrics::STATE_PENDING && $remaining !== null && $remaining < 12 * 3600;
?>
<section class="gw-panel gw-regulator gw-regulator--<?= Html::encode($state) ?>">
    <h2 class="gw-panel__title">Personal data &amp; regulator</h2>
    <dl class="gw-dl">
        <dt>Personal data involved</dt><dd><?= Badge::bool($model->personal_data_involved) ?></dd>
        <?php if ($model->personal_data_involved): ?>
            <dt>Regulator notification</dt><dd><?= $model->regulator_notification_required ? 'Required' : 'Not required' ?></dd>
        <?php endif; ?>
    </dl>
    <?php if ($deadline): ?>
        <div class="gw-countdown-box gw-countdown-box--<?= Html::encode($state) ?><?= $urgent ? ' gw-countdown-box--urgent' : '' ?>"<?= $live ? ' role="status"' : '' ?>>
            <div class="gw-countdown-box__head">
                <i class="bi bi-<?= $icons[$state] ?? 'clock' ?> gw-countdown-box__icon" aria-hidden="true"></i>
                <?= Badge::flag($flag === 'muted' ? 'archived' : $flag, $label) ?>
            </div>
            <?php if ($live): ?>
                <div class="gw-countdown gw-countdown--<?= $state ?>" data-gw-countdown="<?= $deadline->format('c') ?>">
                    <?= Html::encode(Dt::duration($model->getRegulatorSecondsRemaining())) ?>
                    <?= $state === IncidentMetrics::STATE_OVERDUE ? 'overdue' : 'remaining' ?>
                </div>
                <div class="gw-countdown-box__bar" role="presentation"><span style="width: <?= $used ?>%"></span></div>
            <?php endif; ?>
            <div class="gw-countdown-box__deadline">Deadline: <time datetime="<?= $deadline->format('c') ?>"><?= $fmt->asDatetime($deadline->format('Y-m-d H:i:s')) ?></time></div>
        </div>
        <dl class="gw-dl">
            <dt>Regulator notified</dt><dd><?= $fmt->asDatetime($model->regulator_notified_at) ?></dd>
            <dt>Regulator reference</dt><dd><?= $model->regulator_reference ? Html::encode($model->regulator_reference) : $fmt->nullDisplay ?></dd>
            <dt>Data subjects notified</dt><dd><?= $fmt->asDatetime($model->subjects_notified_at) ?></dd>
        </dl>
    <?php endif; ?>
</section>
