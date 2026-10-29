<?php
/**
 * Collapsible 5x5 scoring guide shown on the risk form.
 *
 * @var yii\web\View $this
 */

use app\helpers\RiskMatrix;
use yii\helpers\Html;

$likelihood = RiskMatrix::likelihoodLabels();
$likelihoodGuide = RiskMatrix::likelihoodGuidance();
$impact = RiskMatrix::impactLabels();
$impactGuide = RiskMatrix::impactGuidance();
$ranges = [];
foreach (RiskMatrix::ratingOptions() as $key => $label) {
    $range = RiskMatrix::scoreRange($key);
    if ($range !== null) {
        $ranges[] = $label . ' ' . $range[0] . '–' . $range[1];
    }
}
?>
<details class="gw-scoring-guide">
    <summary>How to score likelihood and impact</summary>
    <div class="gw-scoring-guide__body">
        <p>Score the <strong>harmful outcome</strong>, not the attempt. For example, score "a spoofed email from our domain leads to a fraudulent payment", not "someone tries to spoof our domain". Attempts show that the threat is real. Whether one causes harm depends on your controls.</p>
        <div class="table-responsive">
            <table class="table gw-table gw-scoring-guide__table">
                <thead>
                    <tr><th scope="col">Score</th><th scope="col">Likelihood in the next 12 months</th><th scope="col">Impact (the realistic worst case)</th></tr>
                </thead>
                <tbody>
                <?php for ($n = RiskMatrix::MAX; $n >= RiskMatrix::MIN; $n--): ?>
                    <tr>
                        <th scope="row"><?= $n ?></th>
                        <td><strong><?= Html::encode($likelihood[$n]) ?>:</strong> <?= Html::encode($likelihoodGuide[$n]) ?></td>
                        <td><strong><?= Html::encode($impact[$n]) ?>:</strong> <?= Html::encode($impactGuide[$n]) ?></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <ul class="gw-scoring-guide__tips">
            <li><strong>Impact:</strong> take the highest score across money, operations, reputation, legal or regulatory, and people.</li>
            <li><strong>Inherent:</strong> score as if none of the relevant controls existed.</li>
            <li><strong>Residual:</strong> credit only controls that block or limit harm today. Controls that only monitor or report (e.g. DMARC <code>p=none</code>) do not lower likelihood.</li>
            <li><strong>Controls usually lower likelihood, not impact.</strong> Impact drops only with a control that limits the damage, such as checking payment changes by phone.</li>
            <li><strong>Target:</strong> the score you will accept once the treatment plan is done. It should be achievable with that plan and within your risk appetite.</li>
            <li><strong>Score = likelihood × impact:</strong> <?= Html::encode(implode(' · ', $ranges)) ?>.</li>
        </ul>
    </div>
</details>
