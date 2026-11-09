<?php

namespace app\components;

use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\web\Response;

/**
 * The Content-Security-Policy of every response PHP sends.
 *
 * Inline scripts run only when they carry this request's nonce: the View adds it to the
 * scripts Yii registers (ActiveForm, GridView...) and the layout adds it to its own, so
 * an injected <script> or event handler attribute never runs. Styles still allow
 * 'unsafe-inline', for style attributes.
 *
 * nginx sends its own, stricter policy (no inline scripts at all) only on the responses
 * it serves itself (docker/nginx/default.conf).
 */
final class Csp extends Component implements BootstrapInterface
{
    private ?string $nonce = null;

    public function bootstrap($app): void
    {
        $app->getResponse()->on(Response::EVENT_BEFORE_SEND, function ($event): void {
            $event->sender->getHeaders()->set('Content-Security-Policy', $this->policy());
        });
    }

    /** This request's nonce: 144 random bits, base64 */
    public function nonce(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(18));
    }

    public function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . $this->nonce() . "'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
        ]);
    }
}
