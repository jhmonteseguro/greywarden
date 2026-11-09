<?php

namespace tests\functional;

use tests\FunctionalTestCase;
use yii\web\Response;

/**
 * The Content-Security-Policy PHP sends (components/Csp): inline scripts run only with the
 * request's nonce, so every inline script Greywarden renders must carry it.
 */
class CspTest extends FunctionalTestCase
{
    /** Sends the headers the way Response::send() would, then returns the policy */
    private function policy(): string
    {
        $this->assertNotNull($this->response);
        $this->response->trigger(Response::EVENT_BEFORE_SEND);
        return (string) $this->response->headers->get('Content-Security-Policy');
    }

    private function assertEveryInlineScriptHasTheNonce(int $atLeast): string
    {
        $policy = $this->policy();
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/]{24}'/", $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy, 'no inline scripts without the nonce');
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        preg_match("/'nonce-([^']+)'/", $policy, $m);
        $nonce = $m[1];

        preg_match_all('/<script\b[^>]*>/i', $this->body, $tags);
        $inline = array_values(array_filter($tags[0], static fn($t) => !str_contains($t, 'src=')));
        $this->assertGreaterThanOrEqual($atLeast, count($inline), 'the page has inline scripts to check');
        foreach ($inline as $tag) {
            $this->assertStringContainsString('nonce="' . $nonce . '"', $tag);
        }
        return $nonce;
    }

    public function testSignInPage(): void
    {
        $this->get('/login')->assertOk();
        $this->assertEveryInlineScriptHasTheNonce(1);
    }

    public function testPagesWithFormsGridsAndChartsCarryTheNonce(): void
    {
        $this->loginAs('analyst');
        $nonces = [];
        foreach (['/', '/risks', '/risks/create', '/controls', '/admin/user'] as $url) {
            $this->get($url);
            if ($this->status === 403) {
                continue;
            }
            $this->assertOk();
            $nonces[] = $this->assertEveryInlineScriptHasTheNonce(1);
        }
        $this->assertCount(4, $nonces);
        $this->assertCount(4, array_unique($nonces), 'a new nonce for every request');
    }

    public function testErrorPagesAndRedirectsGetThePolicyToo(): void
    {
        $this->loginAs('viewer');
        $this->get('/no-such-page');
        $this->assertSame(404, $this->status);
        $this->get('/risks/create');
        $this->assertSame(403, $this->status);
        $this->logout();
        $this->get('/risks')->assertRedirect('login');
        $this->assertStringContainsString("'nonce-", $this->policy());
    }
}
