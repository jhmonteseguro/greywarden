<?php

namespace app\modules\report\components;

use app\components\Actor;
use app\helpers\RiskMatrix;
use app\models\Setting;
use app\models\User;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\components\FrameworkCoverage;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\components\IncidentMetrics;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;
use DateTimeImmutable;
use yii\db\Expression;
use yii\db\Query;

/**
 * Works out the figures of a security posture report for a period, as plain
 * arrays that are stored (frozen) with the report and rendered by its views.
 *
 * Start and end of period: risks, controls, decisions and suppliers as they
 * stood at the start and at the end of the period (History rebuilds them from
 * the audit trail), so "rose", "fell" and "closed" are real changes over the
 * period. Incidents, control tests and reviews come from their own dates.
 * Device, cloud and supplier check results are only kept as they are now, so
 * those figures are as at generation and compare with the previous report.
 */
final class ReportBuilder
{
    /** Bump when the shape of the data changes; views check it */
    public const VERSION = 1;

    /** Longest list kept per table; the counts always cover everything */
    public const LIST_LIMIT = 50;

    public const SETTING_APPETITE = 'report.risk_appetite';
    /** Top of the Medium band: High and Critical risks are above appetite */
    public const DEFAULT_APPETITE = 9;

    public const RAG_GREEN = 'green';
    public const RAG_AMBER = 'amber';
    public const RAG_RED = 'red';

    private string $start;
    private string $end;
    /** End of the day before the period: the "start of period" moment */
    private string $startAt;
    /** End of the last day of the period */
    private string $endAt;
    /** First day after the period: something due before it was overdue at the end */
    private string $after;
    private int $appetite;
    /** @var array<int,string> user id => name */
    private array $names;

    /** @param array|null $previous figures of the previous report, to compare the as-at-generation figures with */
    public function __construct(
        private readonly string $type,
        string $start,
        string $end,
        private readonly ?array $previous = null,
    ) {
        $this->start = $start;
        $this->end = $end;
        $this->startAt = (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d') . ' 23:59:59';
        $this->endAt = $end . ' 23:59:59';
        $this->after = (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
        $this->appetite = self::appetite();
    }

    /** Residual scores above this are outside the risk appetite (Admin › Settings › Management reports) */
    public static function appetite(): int
    {
        $value = Setting::get(self::SETTING_APPETITE);
        return is_numeric($value) && $value >= 1 && $value <= 25 ? (int) $value : self::DEFAULT_APPETITE;
    }

    public static function ragOptions(): array
    {
        return [self::RAG_GREEN => 'Green', self::RAG_AMBER => 'Amber', self::RAG_RED => 'Red'];
    }

    /** What each overall status means, printed in the report's legend */
    public static function ragMeanings(): array
    {
        return [
            self::RAG_GREEN => 'On track: no significant concerns.',
            self::RAG_AMBER => 'Some concerns that are being managed; watch these.',
            self::RAG_RED => 'Significant concerns that need management attention or decisions.',
        ];
    }

    public function build(): array
    {
        $this->names = User::find()->select(['full_name', 'id'])->indexBy('id')->column();
        [$prevStart, $prevEnd] = ReportPeriod::previous($this->type, $this->start, $this->end);
        $data = [
            'version' => self::VERSION,
            'period' => [
                'type' => $this->type, 'start' => $this->start, 'end' => $this->end, 'name' => ReportPeriod::name($this->type, $this->start, $this->end),
                'previous_start' => $prevStart, 'previous_end' => $prevEnd, 'lookahead_days' => ReportPeriod::lookaheadDays($this->type),
            ],
            'generated_at' => Actor::now(),
            'appetite' => $this->appetite,
            'appetite_rating' => RiskMatrix::ratingLabel(RiskMatrix::rating($this->appetite)),
            'bands' => RiskMatrix::bands(),
            'previous_report' => $this->previous['reference'] ?? null,
        ];
        $data['risks'] = $this->risks();
        $data['incidents'] = $this->incidents($prevStart, $prevEnd);
        $data['controls'] = $this->controls($prevStart, $prevEnd);
        $data['decisions'] = $this->decisions();
        $data['assets'] = $this->assets();
        $data['suppliers'] = $this->suppliers();
        $data['ahead'] = $this->ahead($data['period']['lookahead_days']);
        $data['activity'] = $this->activity();
        $data['headline'] = $this->headline($data);
        [$data['suggested_rag'], $data['rag_reasons']] = $this->suggestRag($data);
        return $data;
    }

    // ---- Helpers ----------------------------------------------------------------

    private function name(mixed $id): ?string
    {
        return $id ? ($this->names[(int) $id] ?? null) : null;
    }

    private static function cap(array $list): array
    {
        return array_slice(array_values($list), 0, self::LIST_LIMIT);
    }

    private function inPeriod(?string $value): bool
    {
        return $value !== null && $value !== '' && substr($value, 0, 10) >= $this->start && substr($value, 0, 10) <= $this->end;
    }

    private static function isArchived(array $row): bool
    {
        return !empty($row['archived_at']);
    }

    private static function pct(int $part, int $whole): ?int
    {
        return $whole ? (int) round($part / $whole * 100) : null;
    }

    private static function trim(?string $text, int $length = 400): ?string
    {
        $text = trim((string) $text);
        return $text === '' ? null : (mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text);
    }

    // ---- Risks --------------------------------------------------------------------

    private static function riskScore(array $r): ?int
    {
        return RiskMatrix::score($r['residual_likelihood'], $r['residual_impact']) ?? RiskMatrix::score($r['inherent_likelihood'], $r['inherent_impact']);
    }

    private static function riskOpen(array $r): bool
    {
        return !self::isArchived($r) && $r['status'] !== Risk::STATUS_CLOSED;
    }

    private function riskRow(array $r): array
    {
        $score = self::riskScore($r);
        $target = RiskMatrix::score($r['target_likelihood'], $r['target_impact']);
        return [
            'id' => (int) $r['id'], 'ref' => $r['reference'], 'title' => $r['title'], 'owner' => $this->name($r['owner_id']),
            'status' => Risk::statusOptions()[$r['status']] ?? $r['status'],
            'treatment' => $r['treatment'] ? (Risk::treatmentOptions()[$r['treatment']] ?? $r['treatment']) : null,
            'inherent' => RiskMatrix::score($r['inherent_likelihood'], $r['inherent_impact']),
            'residual' => RiskMatrix::score($r['residual_likelihood'], $r['residual_impact']),
            'target' => $target, 'score' => $score, 'rating' => RiskMatrix::rating($score),
        ];
    }

    /** Stock figures of a set of risks at a moment; $after = first day after the moment (for "overdue") */
    private function riskState(array $rows, string $after): array
    {
        $state = ['open' => 0, 'ratings' => array_fill_keys(array_keys(RiskMatrix::ratingOptions()), 0), 'above_appetite' => 0,
            'high_critical' => 0, 'above_target' => 0, 'review_overdue' => 0, 'treatment_overdue' => 0];
        foreach ($rows as $r) {
            if (!self::riskOpen($r)) {
                continue;
            }
            $score = self::riskScore($r);
            $rating = RiskMatrix::rating($score);
            $target = RiskMatrix::score($r['target_likelihood'], $r['target_impact']);
            $state['open']++;
            if ($rating !== null) {
                $state['ratings'][$rating]++;
            }
            $state['above_appetite'] += $score > $this->appetite ? 1 : 0;
            $state['high_critical'] += in_array($rating, ['high', 'critical'], true) ? 1 : 0;
            $state['above_target'] += $target !== null && $score > $target ? 1 : 0;
            $state['review_overdue'] += $r['next_review_date'] && $r['next_review_date'] < $after ? 1 : 0;
            $state['treatment_overdue'] += in_array($r['status'], Risk::TREATMENT_PENDING_STATUSES, true)
                && $r['target_treatment_date'] && $r['target_treatment_date'] < $after ? 1 : 0;
        }
        return $state;
    }

    private function risks(): array
    {
        $before = History::asAt('risk', 'risk', $this->startAt);
        $after = History::asAt('risk', 'risk', $this->endAt);

        $added = $closed = $rose = $fell = $accepted = [];
        $accepts = static fn(array $r) => in_array($r['status'], Risk::ACCEPTANCE_STATUSES, true);
        foreach ($after as $id => $r) {
            $was = $before[$id] ?? null;
            if ($was === null && self::riskOpen($r)) {
                $added[] = $this->riskRow($r);
            }
            if ($was !== null && self::riskOpen($was) && !self::riskOpen($r)) {
                $closed[] = $this->riskRow($r) + ['how' => self::isArchived($r) ? 'Archived' : 'Closed'];
            }
            if ($was !== null && self::riskOpen($was) && self::riskOpen($r)) {
                $from = self::riskScore($was);
                $to = self::riskScore($r);
                if ($from !== $to) {
                    $row = $this->riskRow($r) + ['from' => $from, 'from_rating' => RiskMatrix::rating($from)];
                    $to > $from ? $rose[] = $row : $fell[] = $row;
                }
            }
            if (!self::isArchived($r) && $accepts($r) && ($was === null || !$accepts($was) || $this->inPeriod($r['accepted_at']))) {
                $accepted[] = $this->riskRow($r) + ['accepted_by' => $this->name($r['accepted_by_id']), 'accepted_at' => $r['accepted_at'],
                    'justification' => self::trim($r['acceptance_justification'])];
            }
        }
        $open = array_filter($after, [self::class, 'riskOpen']);
        $byScore = array_map(fn(array $r) => $this->riskRow($r), $open);
        usort($byScore, static fn(array $a, array $b) => [$b['score'], $b['inherent'], $a['ref']] <=> [$a['score'], $a['inherent'], $b['ref']]);

        $heatmap = [];
        foreach ($open as $r) {
            [$l, $i] = $r['residual_likelihood'] && $r['residual_impact']
                ? [$r['residual_likelihood'], $r['residual_impact']] : [$r['inherent_likelihood'], $r['inherent_impact']];
            if ($l && $i) {
                $heatmap[(int) $l][(int) $i] = ($heatmap[(int) $l][(int) $i] ?? 0) + 1;
            }
        }

        $aboveAppetite = array_values(array_filter($byScore, fn(array $r) => $r['score'] > $this->appetite));
        $reviewOverdue = $treatmentOverdue = [];
        foreach ($open as $r) {
            if ($r['next_review_date'] && $r['next_review_date'] < $this->after) {
                $reviewOverdue[] = $this->riskRow($r) + ['due' => $r['next_review_date']];
            }
            if (in_array($r['status'], Risk::TREATMENT_PENDING_STATUSES, true) && $r['target_treatment_date'] && $r['target_treatment_date'] < $this->after) {
                $treatmentOverdue[] = $this->riskRow($r) + ['due' => $r['target_treatment_date']];
            }
        }
        usort($reviewOverdue, static fn($a, $b) => $a['due'] <=> $b['due']);
        usort($treatmentOverdue, static fn($a, $b) => $a['due'] <=> $b['due']);
        usort($rose, static fn($a, $b) => ($b['score'] - $b['from']) <=> ($a['score'] - $a['from']));
        usort($fell, static fn($a, $b) => ($a['score'] - $a['from']) <=> ($b['score'] - $b['from']));

        return [
            'start' => $this->riskState($before, $this->start),
            'end' => $this->riskState($after, $this->after),
            'heatmap' => $heatmap,
            'added' => self::cap($added), 'added_count' => count($added),
            'closed' => self::cap($closed), 'closed_count' => count($closed),
            'rose' => self::cap($rose), 'fell' => self::cap($fell),
            'top' => array_slice($byScore, 0, 5),
            'above_appetite' => self::cap($aboveAppetite),
            'accepted' => self::cap($accepted),
            'reviews' => $this->reviews('risk', $after),
            'review_overdue' => self::cap($reviewOverdue),
            'treatment_overdue' => self::cap($treatmentOverdue),
        ];
    }

    /** Completed reviews of one record type in the period */
    private function reviews(string $entityType, array $records): array
    {
        $rows = (new Query())->from('record_review')->where(['entity_type' => $entityType])
            ->andWhere(['between', 'reviewed_on', $this->start, $this->end])->orderBy(['reviewed_on' => SORT_ASC, 'id' => SORT_ASC])->all();
        $out = [];
        foreach ($rows as $row) {
            $record = $records[(int) $row['entity_id']] ?? null;
            if ($record === null) {
                continue;
            }
            $out[] = [
                'ref' => $record['reference'], 'title' => $record['title'] ?? $record['name'] ?? '', 'reviewed_on' => $row['reviewed_on'],
                'by' => $this->name($row['reviewed_by_id']), 'late' => $row['due_on'] !== null && $row['reviewed_on'] > $row['due_on'],
                'outcome' => self::trim($row['outcome'], 200),
            ];
        }
        return ['count' => count($out), 'late' => count(array_filter($out, static fn($r) => $r['late'])), 'list' => self::cap($out)];
    }

    // ---- Incidents ------------------------------------------------------------------

    private function incidents(string $prevStart, string $prevEnd): array
    {
        $rows = (new Query())->from('incident')->where(['archived_at' => null])->orderBy(['detected_at' => SORT_ASC, 'id' => SORT_ASC])->all();
        $severities = array_keys(Incident::severityOptions());
        $bySeverity = array_fill_keys($severities, ['opened' => 0, 'closed' => 0, 'open_end' => 0]);
        $openedPrev = $closedPrev = $openStart = $personal = 0;
        $regulator = ['required' => 0, IncidentMetrics::STATE_MET => 0, IncidentMetrics::STATE_LATE => 0, IncidentMetrics::STATE_OVERDUE => 0, IncidentMetrics::STATE_PENDING => 0];
        $list = $lessons = $closedIn = [];
        $ids = [];
        foreach ($rows as $r) {
            $detected = $r['detected_at'];
            $closed = $r['closed_at'];
            $openedNow = $this->inPeriod($detected);
            $closedNow = $this->inPeriod($closed);
            $openEnd = $detected <= $this->endAt && ($closed === null || $closed > $this->endAt);
            $openStart += $detected <= $this->startAt && ($closed === null || $closed > $this->startAt) ? 1 : 0;
            $openedPrev += substr($detected, 0, 10) >= $prevStart && substr($detected, 0, 10) <= $prevEnd ? 1 : 0;
            $closedPrev += $closed !== null && substr($closed, 0, 10) >= $prevStart && substr($closed, 0, 10) <= $prevEnd ? 1 : 0;
            $severity = isset($bySeverity[$r['severity']]) ? $r['severity'] : end($severities);
            $bySeverity[$severity]['opened'] += $openedNow ? 1 : 0;
            $bySeverity[$severity]['closed'] += $closedNow ? 1 : 0;
            $bySeverity[$severity]['open_end'] += $openEnd ? 1 : 0;
            if (!$openedNow && !$closedNow && !$openEnd) {
                continue;
            }
            $ids[] = (int) $r['id'];
            if ($openedNow && $r['personal_data_involved']) {
                $personal++;
            }
            if ($openedNow && $r['regulator_notification_required']) {
                $regulator['required']++;
                // As it stood at the end of the period: a notification sent later did not count yet
                $notified = $r['regulator_notified_at'] !== null && $r['regulator_notified_at'] <= $this->endAt ? $r['regulator_notified_at'] : null;
                $regulator[IncidentMetrics::deadlineState(true, $detected, $notified, $this->endAt, Incident::deadlineHours())]++;
            }
            if ($closedNow) {
                $closedIn[] = $r;
                if ($text = self::trim($r['lessons_learned'])) {
                    $lessons[] = ['ref' => $r['reference'], 'title' => $r['title'], 'text' => $text];
                }
            }
            $list[] = [
                'id' => (int) $r['id'], 'ref' => $r['reference'], 'title' => $r['title'], 'severity' => $r['severity'],
                'status' => $openEnd ? $r['status'] : Incident::STATUS_CLOSED,
                'opened' => $openedNow, 'closed' => $closedNow, 'open_end' => $openEnd,
                'detected_at' => $detected, 'closed_at' => $closedNow ? $closed : null,
                'personal_data' => (bool) $r['personal_data_involved'],
                'summary' => self::trim($r['management_summary'] ?: $r['description'], 300),
            ];
        }
        $avg = static function (array $values): ?int {
            $values = array_filter($values, static fn($v) => $v !== null);
            return $values ? (int) round(array_sum($values) / count($values)) : null;
        };
        $severityRank = array_flip($severities);
        usort($list, static fn($a, $b) => [$severityRank[$a['severity']] ?? 9, $a['detected_at']] <=> [$severityRank[$b['severity']] ?? 9, $b['detected_at']]);

        return [
            'opened' => array_sum(array_column($bySeverity, 'opened')),
            'closed' => array_sum(array_column($bySeverity, 'closed')),
            'open_end' => array_sum(array_column($bySeverity, 'open_end')),
            'open_start' => $openStart,
            'opened_previous' => $openedPrev, 'closed_previous' => $closedPrev,
            'by_severity' => $bySeverity,
            'personal_data' => $personal,
            'regulator' => $regulator,
            'times' => [
                'detect' => $avg(array_map(static fn($r) => IncidentMetrics::timeToDetect($r['occurred_at'], $r['detected_at']), $closedIn)),
                'contain' => $avg(array_map(static fn($r) => IncidentMetrics::timeToContain($r['detected_at'], $r['contained_at']), $closedIn)),
                'close' => $avg(array_map(static fn($r) => IncidentMetrics::timeToClose($r['detected_at'], $r['closed_at']), $closedIn)),
                'count' => count($closedIn),
            ],
            'list' => self::cap($list), 'list_count' => count($list),
            'lessons' => self::cap($lessons),
            'failed_controls' => $this->failedControls($ids),
        ];
    }

    /** Controls that failed or were bypassed in the period's incidents */
    private function failedControls(array $incidentIds): array
    {
        if (!$incidentIds) {
            return [];
        }
        $rows = (new Query())->select(['c.reference', 'c.title', 'i.reference AS incident'])->from(['l' => 'incident_control'])
            ->innerJoin(['c' => 'control'], 'c.id = l.control_id')->innerJoin(['i' => 'incident'], 'i.id = l.incident_id')
            ->where(['l.incident_id' => $incidentIds, 'l.role' => Incident::CONTROL_FAILED])->orderBy(['c.reference' => SORT_ASC, 'i.reference' => SORT_ASC])->all();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['reference']] ??= ['ref' => $row['reference'], 'title' => $row['title'], 'incidents' => []];
            $out[$row['reference']]['incidents'][] = $row['incident'];
        }
        return self::cap($out);
    }

    // ---- Controls ---------------------------------------------------------------------

    private static function controlInForce(array $c): bool
    {
        return !self::isArchived($c) && in_array($c['status'], Control::IN_FORCE_STATUSES, true);
    }

    private function controlState(array $rows, string $after): array
    {
        $state = ['in_force' => 0, 'effective' => 0, 'weak' => 0, 'not_tested' => 0, 'test_overdue' => 0, 'target_overdue' => 0,
            'statuses' => array_fill_keys(array_keys(Control::statusOptions()), 0)];
        foreach ($rows as $c) {
            if (self::isArchived($c)) {
                continue;
            }
            if (isset($state['statuses'][$c['status']])) {
                $state['statuses'][$c['status']]++;
            }
            if (in_array($c['status'], Control::PENDING_STATUSES, true) && $c['target_date'] && $c['target_date'] < $after) {
                $state['target_overdue']++;
            }
            if (!self::controlInForce($c)) {
                continue;
            }
            $state['in_force']++;
            $state['effective'] += $c['effectiveness'] === Control::EFFECTIVENESS_EFFECTIVE ? 1 : 0;
            $state['weak'] += in_array($c['effectiveness'], Control::WEAK_RESULTS, true) ? 1 : 0;
            $state['not_tested'] += $c['effectiveness'] === Control::EFFECTIVENESS_NOT_TESTED ? 1 : 0;
            $state['test_overdue'] += $c['status'] === Control::STATUS_IMPLEMENTED && $c['next_test_due'] && $c['next_test_due'] < $after ? 1 : 0;
        }
        $state['effective_pct'] = self::pct($state['effective'], $state['in_force']);
        return $state;
    }

    private function controls(string $prevStart, string $prevEnd): array
    {
        $before = History::asAt('control', 'control', $this->startAt);
        $after = History::asAt('control', 'control', $this->endAt);
        $statuses = Control::statusOptions();

        $changes = $testOverdue = $targetOverdue = [];
        foreach ($after as $id => $c) {
            if (self::isArchived($c)) {
                continue;
            }
            $was = $before[$id] ?? null;
            if ($was === null || $was['status'] !== $c['status'] || self::isArchived($was)) {
                $changes[] = ['ref' => $c['reference'], 'title' => $c['title'], 'from' => $was && !self::isArchived($was) ? ($statuses[$was['status']] ?? $was['status']) : null,
                    'to' => $statuses[$c['status']] ?? $c['status']];
            }
            if ($c['status'] === Control::STATUS_IMPLEMENTED && $c['next_test_due'] && $c['next_test_due'] < $this->after) {
                $testOverdue[] = ['ref' => $c['reference'], 'title' => $c['title'], 'due' => $c['next_test_due'], 'owner' => $this->name($c['owner_id'])];
            }
            if (in_array($c['status'], Control::PENDING_STATUSES, true) && $c['target_date'] && $c['target_date'] < $this->after) {
                $targetOverdue[] = ['ref' => $c['reference'], 'title' => $c['title'], 'due' => $c['target_date'], 'status' => $statuses[$c['status']] ?? $c['status'],
                    'owner' => $this->name($c['owner_id'])];
            }
        }
        usort($testOverdue, static fn($a, $b) => $a['due'] <=> $b['due']);
        usort($targetOverdue, static fn($a, $b) => $a['due'] <=> $b['due']);

        $tests = (new Query())->select(['t.tested_on', 't.result', 't.tested_by_id', 'c.reference', 'c.title'])->from(['t' => 'control_test'])
            ->innerJoin(['c' => 'control'], 'c.id = t.control_id')->where(['between', 't.tested_on', $this->start, $this->end])
            ->orderBy(['t.tested_on' => SORT_ASC, 't.id' => SORT_ASC])->all();
        $results = array_fill_keys([Control::EFFECTIVENESS_EFFECTIVE, Control::EFFECTIVENESS_PARTIAL, Control::EFFECTIVENESS_INEFFECTIVE], 0);
        foreach ($tests as $t) {
            if (isset($results[$t['result']])) {
                $results[$t['result']]++;
            }
        }
        $testsPrev = (int) (new Query())->from('control_test')->where(['between', 'tested_on', $prevStart, $prevEnd])->count();

        $frameworks = [];
        foreach (FrameworkCatalogue::enabled() as $framework) {
            $summary = FrameworkCoverage::summary(FrameworkCoverage::rows($framework));
            $frameworks[$framework] = ['label' => FrameworkCatalogue::shortLabel($framework), 'title' => FrameworkCatalogue::title($framework),
                'previous' => $this->previous['controls']['frameworks'][$framework]['percent'] ?? null]
                + array_intersect_key($summary, array_flip(['applicable', 'met', 'partial', 'planned', 'gap', 'unverified', 'percent']));
        }

        return [
            'start' => $this->controlState($before, $this->start),
            'end' => $this->controlState($after, $this->after),
            'changes' => self::cap($changes), 'changes_count' => count($changes),
            'tests' => ['count' => count($tests), 'previous' => $testsPrev, 'results' => $results,
                'list' => self::cap(array_map(fn($t) => ['ref' => $t['reference'], 'title' => $t['title'], 'tested_on' => $t['tested_on'],
                    'result' => $t['result'], 'by' => $this->name($t['tested_by_id'])], $tests))],
            'test_overdue' => self::cap($testOverdue),
            'target_overdue' => self::cap($targetOverdue),
            'frameworks' => $frameworks,
        ];
    }

    // ---- Decisions ---------------------------------------------------------------------

    private function decisions(): array
    {
        $before = History::asAt('decision', 'decision', $this->startAt);
        $after = History::asAt('decision', 'decision', $this->endAt);
        $statuses = Decision::statusOptions();
        $made = $overdue = [];
        $overdueStart = 0;
        foreach ($after as $d) {
            if (self::isArchived($d)) {
                continue;
            }
            $row = ['ref' => $d['reference'], 'title' => $d['title'], 'date' => $d['decision_date'], 'status' => $statuses[$d['status']] ?? $d['status'],
                'makers' => $d['decision_makers'], 'decision' => self::trim($d['decision_text'], 300)];
            if ($this->inPeriod($d['decision_date'])) {
                $made[] = $row;
            }
            if (in_array($d['status'], Decision::REVIEWABLE_STATUSES, true) && $d['review_date'] && $d['review_date'] < $this->after) {
                $overdue[] = $row + ['due' => $d['review_date']];
            }
        }
        foreach ($before as $d) {
            $overdueStart += !self::isArchived($d) && in_array($d['status'], Decision::REVIEWABLE_STATUSES, true)
                && $d['review_date'] && $d['review_date'] < $this->start ? 1 : 0;
        }
        usort($made, static fn($a, $b) => [$a['date'], $a['ref']] <=> [$b['date'], $b['ref']]);
        usort($overdue, static fn($a, $b) => $a['due'] <=> $b['due']);
        return [
            'made' => self::cap($made), 'made_count' => count($made),
            'proposed' => count(array_filter($after, static fn($d) => !self::isArchived($d) && $d['status'] === Decision::STATUS_PROPOSED)),
            'reviews' => $this->reviews('decision', $after),
            'review_overdue' => self::cap($overdue), 'review_overdue_count' => count($overdue), 'review_overdue_start' => $overdueStart,
        ];
    }

    // ---- Devices, cloud and edge (as at generation) --------------------------------------

    private function assets(): array
    {
        $today = min($this->end, Actor::today());
        $devices = null;
        if (Device::find()->active()->exists()) {
            $total = (int) Device::find()->active()->inService()->count();
            $compliant = (int) Device::find()->active()->inService()->compliant($today)->count();
            $failing = (int) Device::find()->active()->inService()->nonCompliant($today)->count();
            $devices = [
                'total' => $total, 'compliant' => $compliant, 'failing' => $failing, 'not_assessed' => $total - $compliant - $failing,
                'pct' => self::pct($compliant, $total),
                'byod' => (int) Device::find()->active()->inService()->andWhere(['device.ownership' => 'byod'])->count(),
                'stale' => (int) Device::find()->active()->inService()->stale($today)->count(),
                'past_eol' => (int) Device::find()->active()->inService()->pastEol($today)->count(),
                'previous_pct' => $this->previous['assets']['devices']['pct'] ?? null,
            ];
        }
        $cloud = null;
        if (CloudAccount::find()->active()->exists()) {
            $assets = (int) CloudAsset::find()->active()->inService()->count();
            $accounts = (int) CloudAccount::find()->active()->inService()->count();
            $compliant = (int) CloudAsset::find()->active()->inService()->compliant($today)->count() + (int) CloudAccount::find()->active()->inService()->compliant($today)->count();
            $failing = (int) CloudAsset::find()->active()->inService()->nonCompliant($today)->count() + (int) CloudAccount::find()->active()->inService()->nonCompliant($today)->count();
            $cloud = [
                'accounts' => $accounts, 'assets' => $assets, 'compliant' => $compliant, 'failing' => $failing,
                'not_assessed' => $assets + $accounts - $compliant - $failing, 'pct' => self::pct($compliant, $assets + $accounts),
                'internet_facing' => (int) CloudAsset::find()->active()->inService()->internetFacing()->count(),
                'previous_pct' => $this->previous['assets']['cloud']['pct'] ?? null,
            ];
        }
        $edge = null;
        if ((new Query())->from('edge_finding')->exists() || (new Query())->from('edge_pull')->exists()) {
            $found = (new Query())->from('edge_finding')->where(['between', 'first_seen_at', $this->start . ' 00:00:00', $this->endAt]);
            $resolvedCount = fn(string $status) => (int) (new Query())->from('edge_finding')->where(['status' => $status])
                ->andWhere(['between', 'resolved_at', $this->start . ' 00:00:00', $this->endAt])->count();
            $edge = [
                'findings' => (int) (clone $found)->count(),
                'high' => (int) (clone $found)->andWhere(['priority' => 'high'])->count(),
                'blocked' => $resolvedCount('blocked'), 'escalated' => $resolvedCount('escalated'), 'dismissed' => $resolvedCount('dismissed'),
                'open' => (int) (new Query())->from('edge_finding')->where(['status' => 'new'])->count(),
                'blocks_added' => (int) (new Query())->from('intel_blocklist')->where(['between', 'created_at', $this->start . ' 00:00:00', $this->endAt])->count(),
                'top_asns' => array_map(static fn($r) => ['asn' => (int) $r['asn'], 'count' => (int) $r['n']], (clone $found)
                    ->select(['asn', 'n' => new Expression('COUNT(*)')])->andWhere(['not', ['asn' => null]])->groupBy('asn')
                    ->orderBy(['n' => SORT_DESC, 'asn' => SORT_ASC])->limit(5)->all()),
            ];
        }
        return ['devices' => $devices, 'cloud' => $cloud, 'edge' => $edge];
    }

    // ---- Suppliers -------------------------------------------------------------------------

    private function suppliers(): ?array
    {
        if (!Supplier::find()->active()->exists()) {
            return null;
        }
        $today = min($this->end, Actor::today());
        $inService = Supplier::find()->active()->inService();
        $tiers = array_fill_keys(Supplier::TIERS, 0);
        foreach ((clone $inService)->select(['tier', 'n' => new Expression('COUNT(*)')])->groupBy('tier')->asArray()->all() as $row) {
            $tiers[(int) $row['tier']] = (int) $row['n'];
        }
        $row = static fn(Supplier $s, array $extra = []) => ['ref' => $s->reference, 'title' => $s->name, 'tier' => (int) $s->tier] + $extra;
        $assurance = [];
        foreach ((clone $inService)->andWhere(['supplier.tier' => [1, 2]])->with('assurances')->orderBy(['tier' => SORT_ASC, 'name' => SORT_ASC])->all() as $s) {
            /** @var Supplier $s */
            $due = $s->getAssuranceDue($today);
            if (!$s->assurances) {
                $assurance[] = $row($s, ['issue' => 'No assurance on file']);
            } elseif ($due) {
                $assurance[] = $row($s, ['issue' => implode('; ', array_map(static fn($a) => $a->getTypeLabel() . ($a->isExpired($today) ? ' expired ' : ' expires ') . $a->expires_on, $due))]);
            }
        }
        $cutoff = (new DateTimeImmutable($this->end))->modify('+90 days')->format('Y-m-d');
        $contracts = array_map(static fn(Supplier $s) => $row($s, ['ends' => $s->contract_end, 'notice_by' => $s->getNoticeDeadline()]),
            (clone $inService)->andWhere(['between', 'contract_end', $this->after, $cutoff])->orderBy(['contract_end' => SORT_ASC])->all());
        $dpa = array_map(static fn(Supplier $s) => $row($s, ['dpa' => $s->dpa_status ? Supplier::dpaOptions()[$s->dpa_status] : 'Unknown']),
            (clone $inService)->andWhere(['personal_data' => 1])->andWhere(['or', ['dpa_status' => null], ['<>', 'dpa_status', 'signed']])->orderBy(['tier' => SORT_ASC, 'name' => SORT_ASC])->all());
        $incidents = (new Query())->select(['s.reference', 's.name', 'i.reference AS incident', 'i.title AS incident_title'])->from(['l' => 'incident_supplier'])
            ->innerJoin(['s' => 'supplier'], 's.id = l.supplier_id')->innerJoin(['i' => 'incident'], 'i.id = l.incident_id')
            ->where(['i.archived_at' => null])->andWhere(['between', 'i.detected_at', $this->start . ' 00:00:00', $this->endAt])
            ->orderBy(['i.reference' => SORT_ASC])->all();
        $total = (int) (clone $inService)->count();
        $compliant = (int) (clone $inService)->compliant($today)->count();
        $failing = (int) (clone $inService)->nonCompliant($today)->count();
        return [
            'in_service' => $total, 'tiers' => $tiers, 'compliant' => $compliant, 'failing' => $failing, 'pct' => self::pct($compliant, $total),
            'onboarding' => (int) Supplier::find()->active()->onboarding()->count(),
            'review_overdue' => (int) Supplier::find()->active()->reviewOverdue($this->after)->count(),
            'review_overdue_start' => count(array_filter(History::asAt('supplier', 'supplier', $this->startAt), fn(array $s) => !self::isArchived($s)
                && in_array($s['status'], Supplier::IN_SERVICE_STATUSES, true) && $s['next_review_date'] && $s['next_review_date'] < $this->start)),
            'previous_pct' => $this->previous['suppliers']['pct'] ?? null,
            'assurance' => self::cap($assurance), 'contracts' => self::cap($contracts), 'dpa' => self::cap($dpa),
            'incidents' => self::cap(array_map(static fn($r) => ['ref' => $r['reference'], 'title' => $r['name'], 'incident' => $r['incident'],
                'incident_title' => $r['incident_title']], $incidents)),
        ];
    }

    // ---- Looking ahead -------------------------------------------------------------------------

    private function ahead(int $days): array
    {
        $from = $this->after;
        $to = (new DateTimeImmutable($this->end))->modify("+$days days")->format('Y-m-d');
        $items = [];
        $add = static function (string $kind, string $ref, string $title, ?string $due, ?string $who = null) use (&$items, $from, $to) {
            if ($due !== null && $due >= $from && $due <= $to) {
                $items[] = ['kind' => $kind, 'ref' => $ref, 'title' => $title, 'due' => $due, 'owner' => $who];
            }
        };
        foreach (Risk::find()->active()->open()->andWhere(['between', 'next_review_date', $from, $to])->all() as $r) {
            $add('Risk review', $r->reference, $r->title, $r->next_review_date, $this->name($r->owner_id));
        }
        foreach (Risk::find()->active()->andWhere(['status' => Risk::TREATMENT_PENDING_STATUSES])->andWhere(['between', 'target_treatment_date', $from, $to])->all() as $r) {
            $add('Risk treatment due', $r->reference, $r->title, $r->target_treatment_date, $this->name($r->owner_id));
        }
        foreach (Decision::find()->active()->andWhere(['status' => Decision::REVIEWABLE_STATUSES])->andWhere(['between', 'review_date', $from, $to])->all() as $d) {
            $add('Decision review', $d->reference, $d->title, $d->review_date);
        }
        foreach (Control::find()->active()->andWhere(['status' => Control::STATUS_IMPLEMENTED])->andWhere(['between', 'next_test_due', $from, $to])->all() as $c) {
            $add('Control test', $c->reference, $c->title, $c->next_test_due, $this->name($c->owner_id));
        }
        foreach (Control::find()->active()->andWhere(['status' => Control::PENDING_STATUSES])->andWhere(['between', 'target_date', $from, $to])->all() as $c) {
            $add('Control target date', $c->reference, $c->title, $c->target_date, $this->name($c->owner_id));
        }
        foreach (Supplier::find()->active()->inService()->all() as $s) {
            /** @var Supplier $s */
            $add('Supplier review', $s->reference, $s->name, $s->next_review_date, $this->name($s->owner_id));
            if ($s->contract_end && $s->contract_end >= $from) {
                $add('Supplier notice deadline', $s->reference, $s->name, $s->getNoticeDeadline(), $this->name($s->owner_id));
            }
        }
        $expiring = (new Query())->select(['s.reference', 's.name', 'a.type', 'a.expires_on'])->from(['a' => 'supplier_assurance'])
            ->innerJoin(['s' => 'supplier'], 's.id = a.supplier_id')->where(['s.archived_at' => null, 's.status' => Supplier::IN_SERVICE_STATUSES])
            ->andWhere(['between', 'a.expires_on', $from, $to])->all();
        foreach ($expiring as $a) {
            $add('Supplier assurance expires', $a['reference'], $a['name'] . ' · ' . (\app\modules\supplier\models\SupplierAssurance::typeOptions()[$a['type']] ?? $a['type']), $a['expires_on']);
        }
        usort($items, static fn($a, $b) => [$a['due'], $a['kind'], $a['ref']] <=> [$b['due'], $b['kind'], $b['ref']]);
        return ['from' => $from, 'to' => $to, 'count' => count($items), 'list' => self::cap($items)];
    }

    // ---- Activity ------------------------------------------------------------------------

    private function activity(): array
    {
        $types = ['risk', 'control', 'decision', 'incident', 'device', 'cloud_account', 'cloud_asset', 'supplier', 'requirement', 'blocklist', 'edge-finding'];
        $base = (new Query())->from('audit_log')->where(['entity_type' => $types])
            ->andWhere(['not in', 'action', ['export']])->andWhere(['between', 'created_at', $this->start . ' 00:00:00', $this->endAt]);
        $byType = [];
        foreach ((clone $base)->select(['entity_type', 'n' => new Expression('COUNT(*)')])->groupBy('entity_type')->orderBy(['n' => SORT_DESC, 'entity_type' => SORT_ASC])->all() as $row) {
            $byType[$row['entity_type']] = (int) $row['n'];
        }
        $byUser = array_map(static fn($r) => ['name' => $r['username'] ?: 'system', 'count' => (int) $r['n']], (clone $base)
            ->select(['username', 'n' => new Expression('COUNT(*)')])->groupBy('username')->orderBy(['n' => SORT_DESC, 'username' => SORT_ASC])->limit(10)->all());
        return ['total' => array_sum($byType), 'by_type' => $byType, 'by_user' => $byUser];
    }

    // ---- Headline and overall status -------------------------------------------------------

    /**
     * The tiles on the first page: value at the end, value to compare with,
     * which direction is better, and what the comparison is.
     */
    private function headline(array $d): array
    {
        $r = $d['risks'];
        $i = $d['incidents'];
        $c = $d['controls'];
        $overdue = static fn(array $state) => $state['review_overdue'];
        $tiles = [
            ['key' => 'above_appetite', 'label' => 'Risks above appetite', 'value' => $r['end']['above_appetite'], 'compare' => $r['start']['above_appetite'], 'better' => 'down', 'vs' => 'start'],
            ['key' => 'high_critical', 'label' => 'Open high & critical risks', 'value' => $r['end']['high_critical'], 'compare' => $r['start']['high_critical'], 'better' => 'down', 'vs' => 'start'],
            ['key' => 'incidents_opened', 'label' => 'Incidents opened', 'value' => $i['opened'], 'compare' => $i['opened_previous'], 'better' => 'down', 'vs' => 'previous'],
            ['key' => 'incidents_open', 'label' => 'Incidents still open', 'value' => $i['open_end'], 'compare' => $i['open_start'], 'better' => 'down', 'vs' => 'start'],
            ['key' => 'control_effectiveness', 'label' => 'Controls rated effective', 'value' => $c['end']['effective_pct'], 'compare' => $c['start']['effective_pct'], 'unit' => '%', 'better' => 'up', 'vs' => 'start'],
            ['key' => 'overdue_reviews', 'label' => 'Overdue reviews', 'value' => $overdue($r['end']) + $d['decisions']['review_overdue_count'] + ($d['suppliers']['review_overdue'] ?? 0),
                'compare' => $overdue($r['start']) + $d['decisions']['review_overdue_start'] + ($d['suppliers']['review_overdue_start'] ?? 0), 'better' => 'down', 'vs' => 'start',
                'note' => 'Risks, decisions and suppliers'],
            ['key' => 'overdue_tests', 'label' => 'Overdue control tests', 'value' => $c['end']['test_overdue'], 'compare' => $c['start']['test_overdue'], 'better' => 'down', 'vs' => 'start'],
        ];
        if ($d['assets']['devices'] !== null) {
            $tiles[] = ['key' => 'device_compliance', 'label' => 'Device compliance', 'value' => $d['assets']['devices']['pct'], 'compare' => $d['assets']['devices']['previous_pct'],
                'unit' => '%', 'better' => 'up', 'vs' => 'report'];
        } elseif ($d['suppliers'] !== null) {
            $tiles[] = ['key' => 'supplier_compliance', 'label' => 'Suppliers passing checks', 'value' => $d['suppliers']['pct'], 'compare' => $d['suppliers']['previous_pct'],
                'unit' => '%', 'better' => 'up', 'vs' => 'report'];
        }
        return $tiles;
    }

    /**
     * A starting point for the overall status: red for critical risks above
     * appetite, open critical / high incidents or a missed regulator deadline;
     * amber for anything else above appetite or overdue. The author decides.
     * @return array{0:string, 1:string[]}
     */
    private function suggestRag(array $d): array
    {
        $red = $amber = [];
        $criticalAbove = count(array_filter($d['risks']['above_appetite'], static fn($r) => $r['rating'] === 'critical'));
        if ($criticalAbove) {
            $red[] = sprintf('%d critical risk%s above appetite', $criticalAbove, $criticalAbove === 1 ? '' : 's');
        }
        $serious = $d['incidents']['by_severity'][Incident::SEVERITY_CRITICAL]['open_end'] + $d['incidents']['by_severity'][Incident::SEVERITY_HIGH]['open_end'];
        if ($serious) {
            $red[] = sprintf('%d critical or high incident%s still open', $serious, $serious === 1 ? '' : 's');
        }
        $missed = $d['incidents']['regulator'][IncidentMetrics::STATE_LATE] + $d['incidents']['regulator'][IncidentMetrics::STATE_OVERDUE];
        if ($missed > 0) {
            $red[] = sprintf('%d regulator notification%s late or overdue', $missed, $missed === 1 ? '' : 's');
        }
        $above = $d['risks']['end']['above_appetite'] - $criticalAbove;
        if ($above > 0) {
            $amber[] = sprintf('%d risk%s above appetite', $above, $above === 1 ? '' : 's');
        }
        if ($d['risks']['rose']) {
            $amber[] = sprintf('%d risk%s rose', count($d['risks']['rose']), count($d['risks']['rose']) === 1 ? '' : 's');
        }
        $openOther = $d['incidents']['open_end'] - $serious;
        if ($openOther > 0) {
            $amber[] = sprintf('%d other incident%s still open', $openOther, $openOther === 1 ? '' : 's');
        }
        foreach ([
            [$d['headline'][5]['value'], 'overdue review'],
            [$d['controls']['end']['test_overdue'], 'overdue control test'],
            [$d['controls']['end']['weak'], 'control rated partly effective or ineffective', 'controls rated partly effective or ineffective'],
            [count($d['risks']['treatment_overdue']), 'risk treatment past its target date', 'risk treatments past their target date'],
        ] as $item) {
            [$n, $one] = $item;
            if ($n > 0) {
                $amber[] = sprintf('%d %s', $n, $n === 1 ? $one : ($item[2] ?? $one . 's'));
            }
        }
        if ($red) {
            return [self::RAG_RED, array_merge($red, $amber)];
        }
        return $amber ? [self::RAG_AMBER, $amber] : [self::RAG_GREEN, []];
    }
}
