<?php

namespace app\helpers;

use Yii;

/**
 * 5x5 risk matrix: scoring and rating bands.
 *
 * Score = likelihood x impact (1..25). Bands are configured in
 * params['risk.ratingBands'] (defaults below): 1-4 Low, 5-9 Medium,
 * 10-16 High, 20-25 Critical.
 */
final class RiskMatrix
{
    public const MIN = 1;
    public const MAX = 5;

    public const DEFAULT_BANDS = [
        ['key' => 'low', 'label' => 'Low', 'max' => 4],
        ['key' => 'medium', 'label' => 'Medium', 'max' => 9],
        ['key' => 'high', 'label' => 'High', 'max' => 16],
        ['key' => 'critical', 'label' => 'Critical', 'max' => 25],
    ];

    public static function bands(): array
    {
        $bands = (Yii::$app !== null) ? (Yii::$app->params['risk.ratingBands'] ?? null) : null;
        return is_array($bands) && $bands ? $bands : self::DEFAULT_BANDS;
    }

    public static function isValidAxis(mixed $value): bool
    {
        return is_numeric($value) && (int) $value == $value && $value >= self::MIN && $value <= self::MAX;
    }

    public static function score(mixed $likelihood, mixed $impact): ?int
    {
        if (!self::isValidAxis($likelihood) || !self::isValidAxis($impact)) {
            return null;
        }
        return (int) $likelihood * (int) $impact;
    }

    /** Rating key (low|medium|high|critical) for a score, or null. */
    public static function rating(?int $score): ?string
    {
        if ($score === null || $score < 1) {
            return null;
        }
        foreach (self::bands() as $band) {
            if ($score <= $band['max']) {
                return $band['key'];
            }
        }
        $bands = self::bands();
        return end($bands)['key'];
    }

    public static function ratingLabel(?string $rating): ?string
    {
        if ($rating === null) {
            return null;
        }
        foreach (self::bands() as $band) {
            if ($band['key'] === $rating) {
                return $band['label'];
            }
        }
        return ucfirst($rating);
    }

    /** rating key => label, lowest first */
    public static function ratingOptions(): array
    {
        $options = [];
        foreach (self::bands() as $band) {
            $options[$band['key']] = $band['label'];
        }
        return $options;
    }

    /**
     * [min, max] of the scores actually achievable (L x I) within a rating band,
     * e.g. critical => [20, 25] because 17-19 cannot occur on a 5x5 matrix.
     */
    public static function scoreRange(string $rating): ?array
    {
        $scores = [];
        foreach (self::achievableScores() as $score) {
            if (self::rating($score) === $rating) {
                $scores[] = $score;
            }
        }
        return $scores ? [min($scores), max($scores)] : null;
    }

    /** Distinct products L x I for L, I in 1..5 (sorted). */
    public static function achievableScores(): array
    {
        $scores = [];
        for ($l = self::MIN; $l <= self::MAX; $l++) {
            for ($i = self::MIN; $i <= self::MAX; $i++) {
                $scores[$l * $i] = $l * $i;
            }
        }
        ksort($scores);
        return array_values($scores);
    }

    public static function likelihoodLabels(): array
    {
        return [1 => 'Rare', 2 => 'Unlikely', 3 => 'Possible', 4 => 'Likely', 5 => 'Almost certain'];
    }

    public static function impactLabels(): array
    {
        return [1 => 'Negligible', 2 => 'Minor', 3 => 'Moderate', 4 => 'Major', 5 => 'Severe'];
    }

    /** "3 - Possible" style options for dropdowns */
    public static function axisOptions(string $axis): array
    {
        $labels = $axis === 'impact' ? self::impactLabels() : self::likelihoodLabels();
        $out = [];
        foreach ($labels as $n => $label) {
            $out[$n] = $n . ' - ' . $label;
        }
        return $out;
    }
}
