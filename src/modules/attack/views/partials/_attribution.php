<?php
/**
 * MITRE attribution required by the ATT&CK terms of use; shown on every ATT&CK page.
 * @var array|null $release attack_release row
 */

use yii\helpers\Html;

?>
<p class="gw-attack-attribution">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <?php if ($release): ?>
        Data: MITRE ATT&amp;CK&reg; Enterprise v<?= Html::encode($release['version']) ?><?= $release['released_at'] ? ' (' . Html::encode($release['released_at']) . ')' : '' ?>.
    <?php else: ?>
        No ATT&amp;CK catalogue loaded. Run <code class="gw-code">php yii attack/import</code>.
    <?php endif; ?>
    &copy; The MITRE Corporation. Reproduced and distributed with the permission of The MITRE Corporation.
    <?= Html::a('attack.mitre.org', 'https://attack.mitre.org/', ['target' => '_blank', 'rel' => 'noopener noreferrer']) ?>
</p>
