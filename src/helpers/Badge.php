<?php

namespace app\helpers;

use app\modules\attack\components\Coverage;
use app\modules\cloud\models\CloudAsset;
use app\modules\inventory\components\OsLifecycle;
use app\modules\inventory\models\Device;
use yii\helpers\Html;

/**
 * THE single place where status / severity / rating badges are rendered.
 *
 * Markup:  <span class="gw-badge gw-badge--{tone}" data-kind="{kind}" data-value="{value}">Label</span>
 *
 * Tones (style these in web/css/greywarden.css):
 *   critical, high, medium, low, info, success, neutral, muted, danger, warning
 *
 * To restyle a specific value only, target the data attributes, e.g.
 *   .gw-badge[data-kind="incident-status"][data-value="contained"] { ... }
 */
final class Badge
{
    /** kind => value => tone */
    public const TONES = [
        'rating' => ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'critical' => 'critical'],
        'severity' => ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'critical' => 'critical'],
        'criticality' => ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'critical' => 'critical'],
        'risk-status' => [
            'identified' => 'info', 'assessed' => 'info', 'in_treatment' => 'warning',
            'treated' => 'success', 'accepted' => 'neutral', 'closed' => 'success',
        ],
        'treatment' => ['mitigate' => 'info', 'accept' => 'neutral', 'transfer' => 'info', 'avoid' => 'info'],
        'decision-status' => [
            'proposed' => 'warning', 'approved' => 'success', 'rejected' => 'danger',
            'implemented' => 'info', 'superseded' => 'muted',
        ],
        'incident-status' => [
            'new' => 'danger', 'triage' => 'warning', 'investigating' => 'warning', 'contained' => 'info',
            'eradicated' => 'info', 'recovered' => 'success', 'closed' => 'muted',
        ],
        'device-status' => [
            'active' => 'success', 'in_storage' => 'neutral', 'in_repair' => 'warning', 'retired' => 'muted',
            'lost' => 'danger', 'stolen' => 'danger', 'disposed' => 'muted',
        ],
        'ownership' => ['company' => 'neutral', 'byod' => 'info'],
        // Cloud Inventory (app\modules\cloud)
        'cloud-status' => ['active' => 'success', 'stopped' => 'neutral', 'decommissioned' => 'muted', 'suspended' => 'warning', 'closed' => 'muted'],
        'environment' => ['production' => 'high', 'staging' => 'info', 'development' => 'neutral', 'test' => 'neutral', 'shared' => 'info'],
        'exposure' => ['internet' => 'warning'],
        'encryption' => ['yes' => 'success', 'no' => 'danger', 'unknown' => 'neutral'],
        'classification' => ['public' => 'low', 'internal' => 'info', 'confidential' => 'high', 'restricted' => 'critical'],
        // Supplier Register (app\modules\supplier)
        'supplier-status' => ['proposed' => 'neutral', 'due_diligence' => 'info', 'active' => 'success', 'offboarding' => 'warning', 'terminated' => 'muted'],
        'tier' => ['1' => 'critical', '2' => 'medium', '3' => 'low'],
        'access' => ['none' => 'neutral', 'user' => 'info', 'privileged' => 'high'],
        'dpa' => ['signed' => 'success', 'not_signed' => 'danger'],
        'assurance' => ['current' => 'success', 'expiring' => 'warning', 'expired' => 'danger'],
        'user-status' => ['10' => 'success', '0' => 'muted'],
        'role' => ['admin' => 'critical', 'analyst' => 'info', 'viewer' => 'neutral'],
        'compliance' => ['compliant' => 'success', 'non-compliant' => 'danger', 'not-assessed' => 'warning', 'n-a' => 'muted'],
        // Device check result (app\modules\inventory\components\DevicePosture)
        'check-result' => ['pass' => 'success', 'fail' => 'danger', 'unknown' => 'warning'],
        // Control Register (app\modules\control)
        'control-status' => ['planned' => 'warning', 'implementing' => 'info', 'implemented' => 'success', 'retired' => 'muted'],
        'effectiveness' => ['not_tested' => 'neutral', 'effective' => 'success', 'partial' => 'warning', 'ineffective' => 'danger'],
        'control-type' => ['preventive' => 'info', 'detective' => 'info', 'corrective' => 'info'],
        'framework-state' => ['met' => 'success', 'partial' => 'warning', 'planned' => 'info', 'gap' => 'danger', 'excluded' => 'muted'],
        'flag' => [
            'overdue' => 'danger', 'due-soon' => 'warning', 'expiring' => 'warning', 'expired' => 'danger',
            'eol' => 'danger', 'archived' => 'muted', 'met' => 'success', 'late' => 'danger', 'pending' => 'warning',
            'deprecated' => 'muted', 'stale' => 'warning', 'missing' => 'danger', 'os-unsupported' => 'danger', 'os-ending' => 'warning', 'weak-control' => 'warning', 'unverified' => 'neutral',
        ],
        // ATT&CK mitigation adoption (app\modules\attack\components\Coverage)
        'mitigation-state' => ['implemented' => 'success', 'approved' => 'info', 'proposed' => 'warning', 'gap' => 'danger'],
        // Threat intel (app\modules\intel): automated assessment, analyst verdict, source answers, blocklist
        'intel-rating' => [
            'malicious' => 'critical', 'suspicious' => 'high', 'elevated' => 'medium', 'clean' => 'success', 'unknown' => 'neutral', 'special' => 'info',
        ],
        'verdict' => ['malicious' => 'critical', 'suspicious' => 'high', 'benign' => 'success'],
        'source-status' => [
            'hit' => 'danger', 'clean' => 'success', 'info' => 'info', 'error' => 'warning', 'skipped' => 'muted', 'unconfigured' => 'muted',
        ],
        'block-state' => ['blocked' => 'danger', 'expired' => 'warning', 'unblocked' => 'muted'],
        // Edge findings (app\modules\intel\components\edge)
        'edge-status' => ['new' => 'warning', 'blocked' => 'success', 'escalated' => 'danger', 'dismissed' => 'muted', 'ignored' => 'neutral'],
        'edge-priority' => ['high' => 'high', 'medium' => 'medium', 'low' => 'low'],
        'edge-pull' => ['ok' => 'success', 'partial' => 'warning', 'error' => 'danger'],
        'block-type' => ['ip' => 'info', 'cidr' => 'info', 'asn' => 'high'],
        'audit-action' => [
            'create' => 'success', 'update' => 'info', 'archive' => 'warning', 'restore' => 'info', 'delete' => 'danger',
            'attach' => 'info', 'detach' => 'warning', 'export' => 'neutral',
            'regenerate' => 'info', 'approve' => 'success', 'approval_withdraw' => 'warning',
            'review' => 'success', 'review_remove' => 'warning', 'assurance' => 'info', 'assurance_remove' => 'warning',
            'login' => 'neutral', 'logout' => 'neutral', 'login_failed' => 'danger', 'login_blocked' => 'critical',
            'password_change' => 'info', 'password_reset' => 'warning', 'unlock' => 'info',
        ],
        // Management reports (app\modules\report)
        'report-status' => ['draft' => 'warning', 'approved' => 'success'],
        'rag' => ['green' => 'success', 'amber' => 'warning', 'red' => 'danger'],
        'bool' => ['yes' => 'success', 'no' => 'danger'],
        // Incident CIA impact (app\modules\incident\models\Incident::IMPACT_ATTRIBUTES)
        'cia' => ['confidentiality' => 'info', 'integrity' => 'info', 'availability' => 'info'],
    ];

    public static function render(string $kind, string|int|null $value, ?string $label = null, array $options = []): string
    {
        if ($value === null || $value === '') {
            return '<span class="gw-null">&mdash;</span>';
        }
        $value = (string) $value;
        $tone = self::TONES[$kind][$value] ?? 'neutral';
        $label ??= ucwords(str_replace(['_', '-'], ' ', $value));
        Html::addCssClass($options, ['gw-badge', 'gw-badge--' . $tone]);
        $options['data-kind'] = $kind;
        $options['data-value'] = $value;
        if (isset($options['icon'])) {
            $label = Html::tag('i', '', ['class' => 'bi bi-' . $options['icon'], 'aria-hidden' => 'true']) . ' ' . Html::encode($label);
            unset($options['icon']);
        } else {
            $label = Html::encode($label);
        }
        return Html::tag('span', $label, $options);
    }

    /** Risk rating (low|medium|high|critical) */
    public static function rating(?string $rating): string
    {
        return self::render('rating', $rating, RiskMatrix::ratingLabel($rating));
    }

    /** Score with its rating, e.g. "12 · High" */
    public static function score(?int $score): string
    {
        if ($score === null) {
            return self::render('rating', null);
        }
        $rating = RiskMatrix::rating($score);
        return self::render('rating', $rating, $score . ' · ' . RiskMatrix::ratingLabel($rating), ['class' => 'gw-badge--score']);
    }

    public static function severity(?string $severity): string
    {
        return self::render('severity', $severity);
    }

    public static function criticality(?string $criticality): string
    {
        return self::render('criticality', $criticality);
    }

    /** Supplier tier 1-3, with what puts it there as the tooltip */
    public static function tier(int|string|null $tier, ?string $title = null): string
    {
        return self::render('tier', $tier, $tier ? 'Tier ' . (int) $tier : null, array_filter(['title' => $title]));
    }

    /** Status badge, labels supplied by the model's options array */
    public static function status(string $kind, ?string $status, array $labels = []): string
    {
        return self::render($kind, $status, $labels[$status] ?? null);
    }

    public static function bool(mixed $value, string $yes = 'Yes', string $no = 'No'): string
    {
        return self::render('bool', $value ? 'yes' : 'no', $value ? $yes : $no, ['icon' => $value ? 'check-lg' : 'x-lg']);
    }

    /**
     * CIA properties an incident affected (Incident::getImpacts()).
     * Compact renders C / I / A with the full name as a tooltip.
     */
    public static function impacts(array $impacts, bool $compact = false): string
    {
        if ($impacts === []) {
            return $compact ? '' : self::render('cia', null);
        }
        $out = [];
        foreach ($impacts as $label) {
            $out[] = self::render('cia', strtolower($label), $compact ? $label[0] : $label, $compact ? ['title' => $label] : []);
        }
        return implode(' ', $out);
    }

    public static function compliance(?bool $compliant, ?string $title = null): string
    {
        if ($compliant === null) {
            return self::render('compliance', 'n-a', 'N/A');
        }
        return self::render(
            'compliance',
            $compliant ? 'compliant' : 'non-compliant',
            $compliant ? 'Compliant' : 'Non-compliant',
            array_filter(['icon' => $compliant ? 'shield-check' : 'shield-exclamation', 'title' => $title])
        );
    }

    /** Device / cloud posture: compliant | non-compliant | not-assessed (DevicePosture::STATE_*) */
    public static function posture(string $state, ?string $title = null): string
    {
        $icons = ['compliant' => 'shield-check', 'non-compliant' => 'shield-exclamation', 'not-assessed' => 'shield'];
        return self::render('compliance', $state, \app\modules\inventory\components\DevicePosture::stateLabels()[$state] ?? null,
            array_filter(['icon' => $icons[$state] ?? null, 'title' => $title]));
    }

    /** Device / cloud check result: pass | fail | unknown */
    public static function checkResult(string $result): string
    {
        $icons = ['pass' => 'check-lg', 'fail' => 'x-lg', 'unknown' => 'question-lg'];
        return self::render('check-result', $result, \app\modules\inventory\components\DevicePosture::resultLabels()[$result] ?? null, ['icon' => $icons[$result] ?? null]);
    }

    /** Control effectiveness: not_tested | effective | partial | ineffective */
    public static function effectiveness(?string $effectiveness): string
    {
        return self::render('effectiveness', $effectiveness, \app\modules\control\models\Control::effectivenessOptions()[$effectiveness] ?? null);
    }

    /** Framework requirement roll-up: met | partial | planned | gap | excluded */
    public static function frameworkState(string $state, ?string $title = null): string
    {
        return self::render('framework-state', $state, \app\modules\control\components\FrameworkCoverage::stateLabels()[$state] ?? null, array_filter(['title' => $title]));
    }

    /** Small warning flag, e.g. Badge::flag('overdue', 'Review overdue') */
    public static function flag(string $type, string $label, ?string $title = null): string
    {
        return self::render('flag', $type, $label, array_filter(['class' => 'gw-badge--flag', 'title' => $title]));
    }

    /** OS end-of-support flag from OsLifecycle::evaluate(); '' while supported or unknown */
    public static function osSupport(array $support): string
    {
        if (!in_array($support['state'], [OsLifecycle::UNSUPPORTED, OsLifecycle::ENDING], true)) {
            return '';
        }
        $date = \Yii::$app->formatter->asDate($support['endDate']) . ($support['estimated'] ? ' (estimated)' : '');
        return $support['state'] === OsLifecycle::UNSUPPORTED
            ? self::flag('os-unsupported', 'Unsupported OS', 'Security updates ended ' . $date)
            : self::flag('os-ending', 'OS support ending', 'Security updates end ' . $date);
    }

    /** Last-seen flag for a synced device or cloud asset: 'missing' past its staleCriticalDays(), 'stale' past its staleDays() */
    public static function lastSeen(Device|CloudAsset $record, ?string $today = null): string
    {
        $tone = $record->getLastSeenTone($today);
        if ($tone === null) {
            return '';
        }
        $title = sprintf('No sync from %s for more than %d days', $record->getSourceLabel() ?? 'its source',
            $tone === 'danger' ? $record::staleCriticalDays() : $record::staleDays());
        return self::flag($tone === 'danger' ? 'missing' : 'stale', 'Not seen ' . $record->getLastSeenDays($today) . 'd', $title);
    }

    /** ATT&CK mitigation adoption state: implemented | approved | proposed | gap */
    public static function mitigationState(string $state, ?string $title = null): string
    {
        return self::render('mitigation-state', $state, Coverage::stateLabels()[$state] ?? null, array_filter(['title' => $title]));
    }

    /** Automated intel assessment, e.g. Badge::intelRating('malicious', 87) -> "87 · Malicious" */
    public static function intelRating(?string $rating, ?int $score = null): string
    {
        if ($rating === null) {
            return self::render('intel-rating', null);
        }
        $label = \app\modules\intel\components\Assessment::RATINGS[$rating]['label'] ?? ucfirst($rating);
        return self::render('intel-rating', $rating, $score !== null && $rating !== 'special' ? $score . ' · ' . $label : $label,
            $score !== null && $rating !== 'special' ? ['class' => 'gw-badge--score'] : []);
    }

    /** Analyst verdict on an indicator */
    public static function verdict(?string $verdict): string
    {
        return self::render('verdict', $verdict, null, $verdict ? ['icon' => $verdict === 'benign' ? 'person-check' : 'person-exclamation'] : []);
    }

    /** Blocklist entry state: blocked | expired | unblocked */
    public static function blockState(string $state): string
    {
        return self::render('block-state', $state, null, ['icon' => ['blocked' => 'slash-circle', 'expired' => 'hourglass-bottom', 'unblocked' => 'check-circle'][$state] ?? null]);
    }

    public static function auditAction(string $action, ?string $label = null): string
    {
        return self::render('audit-action', $action, $label);
    }
}
