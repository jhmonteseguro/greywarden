<?php

namespace app\modules\threat\components;

use app\models\DomainRecord;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use app\modules\threat\models\ThreatCve;
use app\modules\threat\models\ThreatTriage;
use Yii;
use yii\web\NotFoundHttpException;

/**
 * Raises a risk or an incident from a story. The form opens filled in from the story
 * (?threat=<triage id>) for the analyst to finish; only saving it links the two and marks
 * the story relevant. Scores, category and owner stay the analyst's call.
 *
 * Feed text reaches these records as plain text and is shown encoded, like the rest of them.
 */
final class StoryRaiser
{
    public const PARAM = 'threat';
    public const MAX_COVERAGE = 5;

    private const LINKS = [
        Risk::class => ['threat_triage_risk', 'risk_id'],
        Incident::class => ['threat_triage_incident', 'incident_id'],
    ];

    /**
     * The story this request raises a record from.
     * @throws NotFoundHttpException when it is not one of this organisation's
     */
    public static function requested(): ?ThreatTriage
    {
        $id = Yii::$app->request->get(self::PARAM);
        if ($id === null) {
            return null;
        }
        $triage = is_scalar($id) ? ThreatTriage::find()->forOrganisation()->andWhere(['threat_triage.id' => (int) $id])->one() : null;
        if ($triage === null) {
            throw new NotFoundHttpException('Story not found.');
        }
        return $triage;
    }

    public static function fillRisk(Risk $risk, ThreatTriage $triage): void
    {
        $story = $triage->story;
        $risk->title = mb_substr($story->title, 0, 255);
        $risk->description = self::description($triage);
        $risk->scope = mb_substr(implode(', ', $triage->is_matched ? $triage->getMatchedTermList() : []), 0, 255) ?: null;
        $risk->threat_source = implode(', ', array_map(static fn($group) => $group->name, $story->getActors())) ?: null;
        $risk->vulnerability = implode("\n", array_map([self::class, 'cveLine'], $story->getSortedCves())) ?: null;
    }

    public static function fillIncident(Incident $incident, ThreatTriage $triage): void
    {
        $incident->title = mb_substr($triage->story->title, 0, 255);
        $incident->description = self::description($triage);
        $incident->severity = $triage->priority;
        $incident->reported_by = 'Threat Watch ' . $triage->getReferenceValue();
    }

    /** Saves a new record, and ties it to the story the request raised it from, or neither */
    public static function save(DomainRecord $record): bool
    {
        $triage = $record->isNewRecord ? self::requested() : null;
        if ($triage === null) {
            return $record->save();
        }
        $tx = Yii::$app->db->beginTransaction();
        try {
            $saved = $record->save();
            if ($saved) {
                self::link($triage, $record);
            }
            $saved ? $tx->commit() : $tx->rollBack();
            return $saved;
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    private static function link(ThreatTriage $triage, DomainRecord $record): void
    {
        [$table, $column] = self::LINKS[$record::class];
        Yii::$app->db->createCommand()->insert($table, [
            'triage_id' => $triage->id,
            $column => $record->id,
            'created_at' => $record->created_at,
            'created_by' => $record->created_by,
        ])->execute();
        if ($triage->status !== ThreatTriage::STATUS_RELEVANT) {
            $triage->resolve(ThreatTriage::STATUS_RELEVANT);
        }
    }

    private static function description(ThreatTriage $triage): string
    {
        $story = $triage->story;
        $lines = [];
        foreach (array_slice($story->articles, 0, self::MAX_COVERAGE) as $article) {
            $lines[] = sprintf('- %s: %s (%s)', $article->source->name, $article->title, $article->url);
        }
        $parts = array_filter([$story->summary, $lines ? "Coverage:\n" . implode("\n", $lines) : null]);
        return implode("\n\n", [...$parts, 'Raised from Threat Watch ' . $triage->getReferenceValue() . '.']);
    }

    private static function cveLine(ThreatCve $cve): string
    {
        $facts = array_filter([
            trim($cve->vendor . ' ' . $cve->product),
            $cve->cvss_score !== null ? 'CVSS ' . number_format((float) $cve->cvss_score, 1) : null,
            $cve->getEpssPercent() !== null ? 'EPSS ' . $cve->getEpssPercent() : null,
            $cve->getInKev() ? 'in CISA KEV' : null,
        ]);
        return $cve->cve_id . ($facts ? ' (' . implode(', ', $facts) . ')' : '');
    }
}
