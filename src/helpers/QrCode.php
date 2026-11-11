<?php

namespace app\helpers;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

/**
 * QR codes as SVG data URIs, drawn on the server (no third-party service ever sees the content,
 * which for authenticator apps is a secret).
 */
final class QrCode
{
    public static function dataUri(string $text): string
    {
        return (new Generator(new QROptions([
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'outputBase64' => true,
            'svgAddXmlHeader' => false,
            // Dark modules only; the page puts the code on a white background
            'drawLightModules' => false,
        ])))->render($text);
    }
}
