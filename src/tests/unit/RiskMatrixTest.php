<?php

namespace tests\unit;

use app\helpers\RiskMatrix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RiskMatrixTest extends TestCase
{
    public function testScoreIsLikelihoodTimesImpact(): void
    {
        $this->assertSame(1, RiskMatrix::score(1, 1));
        $this->assertSame(12, RiskMatrix::score(3, 4));
        $this->assertSame(25, RiskMatrix::score(5, 5));
        $this->assertSame(12, RiskMatrix::score('4', '3'), 'numeric strings from forms are accepted');
    }

    public function testScoreRejectsOutOfRangeOrMissingValues(): void
    {
        $this->assertNull(RiskMatrix::score(0, 3));
        $this->assertNull(RiskMatrix::score(6, 3));
        $this->assertNull(RiskMatrix::score(null, 3));
        $this->assertNull(RiskMatrix::score('', ''));
        $this->assertNull(RiskMatrix::score(2.5, 2));
    }

    public static function bandProvider(): array
    {
        return [
            [1, 'low'], [4, 'low'],
            [5, 'medium'], [6, 'medium'], [9, 'medium'],
            [10, 'high'], [12, 'high'], [16, 'high'],
            [20, 'critical'], [25, 'critical'],
        ];
    }

    #[DataProvider('bandProvider')]
    public function testRatingBands(int $score, string $expected): void
    {
        $this->assertSame($expected, RiskMatrix::rating($score));
    }

    public function testEveryCellOfTheMatrixHasARating(): void
    {
        $counts = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        for ($l = 1; $l <= 5; $l++) {
            for ($i = 1; $i <= 5; $i++) {
                $counts[RiskMatrix::rating(RiskMatrix::score($l, $i))]++;
            }
        }
        $this->assertSame(25, array_sum($counts));
        $this->assertSame(['low' => 8, 'medium' => 7, 'high' => 7, 'critical' => 3], $counts);
    }

    public function testRatingOfNullOrZeroIsNull(): void
    {
        $this->assertNull(RiskMatrix::rating(null));
        $this->assertNull(RiskMatrix::rating(0));
    }

    public function testScoreRangesUseAchievableScores(): void
    {
        $this->assertSame([1, 4], RiskMatrix::scoreRange('low'));
        $this->assertSame([5, 9], RiskMatrix::scoreRange('medium'));
        $this->assertSame([10, 16], RiskMatrix::scoreRange('high'));
        $this->assertSame([20, 25], RiskMatrix::scoreRange('critical'));
        $this->assertNull(RiskMatrix::scoreRange('nonsense'));
    }

    public function testLabels(): void
    {
        $this->assertSame('Critical', RiskMatrix::ratingLabel('critical'));
        $this->assertSame(['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'], RiskMatrix::ratingOptions());
        $this->assertSame('3 - Possible', RiskMatrix::axisOptions('likelihood')[3]);
        $this->assertSame('5 - Severe', RiskMatrix::axisOptions('impact')[5]);
    }

    public function testBandsAreConfigurableViaParams(): void
    {
        $original = \Yii::$app->params['risk.ratingBands'];
        \Yii::$app->params['risk.ratingBands'] = [
            ['key' => 'low', 'label' => 'Low', 'max' => 6],
            ['key' => 'high', 'label' => 'High', 'max' => 25],
        ];
        try {
            $this->assertSame('low', RiskMatrix::rating(6));
            $this->assertSame('high', RiskMatrix::rating(8));
        } finally {
            \Yii::$app->params['risk.ratingBands'] = $original;
        }
    }
}
