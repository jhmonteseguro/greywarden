<?php

namespace app\modules\incident\components;

use app\helpers\Dt;
use app\models\Setting;
use app\modules\incident\models\Incident;
use Yii;

/**
 * Plain-English wording for the incident PDF report, so it reads for management
 * and non-technical staff: what each status and severity means (editable in
 * Admin › Settings › Incident reports, blank = the default below), what the
 * affected security properties mean and where the regulator notice stands.
 */
final class IncidentWording
{
    public const KIND_STATUS = 'status';
    public const KIND_SEVERITY = 'severity';

    /** status => default meaning */
    public const STATUS_DEFAULTS = [
        Incident::STATUS_NEW => 'The incident has just been reported and has not been assessed yet.',
        Incident::STATUS_TRIAGE => 'The security team is assessing how serious the incident is and who needs to be involved.',
        Incident::STATUS_INVESTIGATING => 'The security team is working out what happened, how it happened and what has been affected.',
        Incident::STATUS_CONTAINED => 'The incident has been stopped from spreading or getting worse. Clean-up is under way.',
        Incident::STATUS_ERADICATED => 'The cause has been removed (for example malicious software deleted or unauthorised access cut off). Systems are being restored.',
        Incident::STATUS_RECOVERED => 'Affected systems and services are back to normal. The final review is under way.',
        Incident::STATUS_CLOSED => 'The incident is resolved and has been reviewed. Any follow-up improvements are tracked separately.',
    ];

    /** severity => default meaning */
    public const SEVERITY_DEFAULTS = [
        Incident::SEVERITY_CRITICAL => 'Serious harm to the organisation is happening or likely: major disruption, a significant data breach, or legal or regulatory consequences. Senior management must be kept informed.',
        Incident::SEVERITY_HIGH => 'Significant impact on important systems, information or people. It needs urgent attention and management awareness.',
        Incident::SEVERITY_MEDIUM => 'Limited impact on a small number of systems or people. The security team handles it as a priority.',
        Incident::SEVERITY_LOW => 'Little or no real impact. Recorded for awareness and handled as routine work.',
    ];

    /** CIA impact attribute => what it means */
    public const IMPACT_TEXT = [
        'impact_confidentiality' => 'Information may have been seen by people who should not have seen it.',
        'impact_integrity' => 'Information or systems may have been changed without permission.',
        'impact_availability' => 'Systems or services were unavailable or slowed down.',
    ];

    /** @return array<string,array<string,string>> kind => key => default text */
    public static function defaults(): array
    {
        return [self::KIND_STATUS => self::STATUS_DEFAULTS, self::KIND_SEVERITY => self::SEVERITY_DEFAULTS];
    }

    /** "incident.status.contained" */
    public static function settingName(string $kind, string $key): string
    {
        return "incident.$kind.$key";
    }

    /** What the status means, as worded in the settings (or by default) */
    public static function status(string $status): string
    {
        return self::text(self::KIND_STATUS, $status);
    }

    /** What the severity means, as worded in the settings (or by default) */
    public static function severity(string $severity): string
    {
        return self::text(self::KIND_SEVERITY, $severity);
    }

    public static function text(string $kind, string $key): string
    {
        $saved = trim((string) Setting::get(self::settingName($kind, $key)));
        return $saved !== '' ? $saved : (self::defaults()[$kind][$key] ?? '');
    }

    /** @return string[] what each affected security property means */
    public static function impacts(Incident $incident): array
    {
        return array_values(array_intersect_key(self::IMPACT_TEXT, $incident->getImpacts()));
    }

    /** Where the incident stands on personal data and the regulator, in one or two sentences */
    public static function regulator(Incident $incident, ?string $now = null): string
    {
        if (!$incident->personal_data_involved) {
            return 'No personal data is known to be involved.';
        }
        $fmt = Yii::$app->formatter;
        $deadline = $incident->getRegulatorDeadline();
        $deadlineText = $deadline ? $fmt->asDatetime($deadline->format('Y-m-d H:i:s')) : '';
        $hours = Incident::deadlineHours();
        $reference = $incident->regulator_reference ? " (reference {$incident->regulator_reference})" : '';

        $text = match ($incident->getRegulatorDeadlineState($now)) {
            IncidentMetrics::STATE_PENDING => sprintf('Personal data is involved. The incident must be reported to the data protection regulator by %s (%s left).',
                $deadlineText, Dt::duration($incident->getRegulatorSecondsRemaining($now))),
            IncidentMetrics::STATE_OVERDUE => sprintf('Personal data is involved. The incident should have been reported to the data protection regulator by %s and has not been reported yet.',
                $deadlineText),
            IncidentMetrics::STATE_MET => sprintf('Personal data is involved. The incident was reported to the data protection regulator on %s%s, within the %d-hour deadline.',
                $fmt->asDatetime($incident->regulator_notified_at), $reference, $hours),
            IncidentMetrics::STATE_LATE => sprintf('Personal data is involved. The incident was reported to the data protection regulator on %s%s, after the %d-hour deadline of %s.',
                $fmt->asDatetime($incident->regulator_notified_at), $reference, $hours, $deadlineText),
            default => 'Personal data is involved. The incident has been assessed as not needing to be reported to the data protection regulator.',
        };
        if ($incident->subjects_notified_at) {
            $text .= ' The people affected were told on ' . $fmt->asDatetime($incident->subjects_notified_at) . '.';
        }
        return $text;
    }
}
