<?php

namespace tests\unit;

use app\components\Csp;
use app\components\View;
use PHPUnit\Framework\TestCase;
use Yii;

class CspTest extends TestCase
{
    protected function tearDown(): void
    {
        Yii::$app->clear('csp');
    }

    public function testNonceIsRandomAndStableWithinARequest(): void
    {
        $a = new Csp();
        $b = new Csp();
        $this->assertSame($a->nonce(), $a->nonce());
        $this->assertNotSame($a->nonce(), $b->nonce());
        $this->assertSame(18, strlen(base64_decode($a->nonce(), true)));
        $this->assertStringContainsString("script-src 'self' 'nonce-" . $a->nonce() . "'", $a->policy());
        $this->assertStringContainsString("default-src 'self'", $a->policy());
        $this->assertStringContainsString("base-uri 'self'", $a->policy());
    }

    public function testWithNonceMarksOnlyInlineScriptsWithoutOne(): void
    {
        $view = new View();
        $html = '<script>a()</script><SCRIPT type="module">b()</SCRIPT><script src="/js/x.js"></script>'
            . '<script nonce="other">c()</script><scripts>not a tag</scripts>';
        $this->assertSame($html, $view->withNonce($html), 'unchanged without the csp component (console)');

        Yii::$app->set('csp', $csp = new Csp());
        $n = $csp->nonce();
        $this->assertSame(
            '<script nonce="' . $n . '">a()</script><script nonce="' . $n . '" type="module">b()</SCRIPT><script src="/js/x.js"></script>'
            . '<script nonce="other">c()</script><scripts>not a tag</scripts>',
            $view->withNonce($html)
        );
        $this->assertSame('', $view->withNonce(''));
    }

    public function testEndBodyGivesHandlerOutputTheNonce(): void
    {
        Yii::$app->set('csp', $csp = new Csp());
        $view = new View();
        $view->on(View::EVENT_END_BODY, static function (): void {
            echo '<div id="toolbar"></div><script>toolbar()</script>';
        });
        ob_start();
        $view->endBody();
        $out = ob_get_clean();
        $this->assertStringContainsString('<script nonce="' . $csp->nonce() . '">toolbar()</script>', $out);
        $this->assertStringEndsWith(View::PH_BODY_END, $out);
    }

    public function testRenderedScriptBlocksGetTheNonce(): void
    {
        Yii::$app->set('csp', $csp = new Csp());
        $view = new View();
        $view->registerJs('init();', View::POS_END);
        $view->registerJs('head();', View::POS_HEAD);
        $view->registerJs('begin();', View::POS_BEGIN);
        $page = $view->renderFile('@app/tests/_data/csp-page.php');
        $this->assertSame(3, substr_count($page, '<script nonce="' . $csp->nonce() . '"'), $page);
        $this->assertStringNotContainsString('<script>', $page);
    }
}
