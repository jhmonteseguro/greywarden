<?php
/**
 * Logo mark: the logo uploaded in Admin › Settings, or the built-in Greywarden
 * mark (a shield enclosing a watchtower with a lit window). The built-in
 * mark's colours come from CSS tokens (--gw-logo-from / --gw-logo-to /
 * --gw-logo-ink), see "Brand" in web/css/greywarden.css. web/favicon.svg is
 * the same drawing.
 *
 * @var string|null $class extra CSS class
 */

use app\components\Branding;
use yii\helpers\Html;

if ($logoUrl = Branding::logoUrl()) {
    echo Html::img($logoUrl, ['class' => trim('gw-logo gw-logo--custom ' . ($class ?? '')), 'alt' => '']);
    return;
}
$class = trim('gw-logo ' . ($class ?? ''));
$gid = 'gw-logo-g' . substr(md5(uniqid('', true)), 0, 6);
?>
<svg class="<?= $class ?>" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="<?= $gid ?>" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" class="gw-logo__stop-a"/>
            <stop offset="1" class="gw-logo__stop-b"/>
        </linearGradient>
    </defs>
    <path class="gw-logo__shield" fill="url(#<?= $gid ?>)" d="M16 2 4.5 6.2v8.3c0 7.1 4.9 13.1 11.5 15.5 6.6-2.4 11.5-8.4 11.5-15.5V6.2L16 2z"/>
    <path class="gw-logo__tower" d="M10.5 9h2.4v2h1.9V9h2.4v2h1.9V9h2.4v4.6L20.1 15v9h-8.2v-9l-1.4-1.4z"/>
    <rect class="gw-logo__window" x="15" y="16.4" width="2" height="3.6" rx="1"/>
</svg>
