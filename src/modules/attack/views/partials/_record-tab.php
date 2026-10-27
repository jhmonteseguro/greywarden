<?php
/**
 * "ATT&CK" tab on incident and risk view pages: the mapped techniques in
 * kill-chain order and the coverage of MITRE's mitigations for them.
 *
 * @var yii\web\View $this
 * @var app\modules\attack\models\AttackTechnique[] $techniques
 * @var string $intro sentence describing what the techniques mean for this record
 * @var bool $canEdit
 * @var array $editUrl
 */

use app\modules\attack\components\Catalogue;
use app\modules\attack\widgets\KillChain;
use app\modules\attack\widgets\MitigationCoverage;
use yii\helpers\Html;

?>
<div class="row g-4 gw-attack-tab">
    <div class="col-xl-5">
        <section class="gw-panel">
            <header class="gw-panel__header">
                <h2 class="gw-panel__title"><i class="bi bi-crosshair" aria-hidden="true"></i> Techniques by tactic</h2>
                <div class="gw-panel__tools"><?= Html::a('Matrix', ['/attack/technique/index'], ['class' => 'gw-panel__link']) ?></div>
            </header>
            <p class="gw-muted gw-attack-tab__intro"><?= Html::encode($intro) ?></p>
            <?= KillChain::widget([
                'techniques' => $techniques,
                'emptyText' => 'No ATT&CK techniques mapped yet.',
            ]) ?>
            <?php if (!$techniques && $canEdit): ?>
                <p><?= Html::a('<i class="bi bi-plus-lg"></i> Map techniques', $editUrl, ['class' => 'btn btn-outline-secondary btn-sm gw-btn']) ?></p>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-xl-7">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-shield-check" aria-hidden="true"></i> Mitigation coverage</h2>
            <?= MitigationCoverage::widget(['techniqueIds' => array_map(static fn($t) => $t->id, $techniques)]) ?>
        </section>
    </div>
</div>
<p class="gw-attack-attribution"><i class="bi bi-info-circle" aria-hidden="true"></i>
    <?= Html::encode(Catalogue::label()) ?> · &copy; The MITRE Corporation, reproduced with permission.
    Mitigation status comes from controls in the Control Register (in place once implemented) and decisions in the Decision Log (planned once approved).</p>
