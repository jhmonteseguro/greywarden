<?php

namespace app\components;

use Yii;
use yii\helpers\Html;

/**
 * Adds the request's CSP nonce (components/Csp) to the inline scripts Yii renders: the
 * init code of ActiveForm, GridView and the other widgets, registered with registerJs().
 * Script files (<script src>) need none: 'self' allows them.
 */
class View extends \yii\web\View
{
    /**
     * What end-of-body handlers print (in development, the debug toolbar and its script) gets the
     * nonce too. Only their output: the page itself is never rewritten, so a <script> that found
     * its way into page content still has none and does not run.
     */
    public function endBody()
    {
        ob_start();
        ob_implicit_flush(false);
        $this->trigger(self::EVENT_END_BODY);
        echo $this->withNonce((string) ob_get_clean());
        echo self::PH_BODY_END;

        foreach (array_keys($this->assetBundles) as $bundle) {
            $this->registerAssetFiles($bundle);
        }
    }

    protected function renderHeadHtml()
    {
        return $this->withNonce(parent::renderHeadHtml());
    }

    protected function renderBodyBeginHtml()
    {
        return $this->withNonce(parent::renderBodyBeginHtml());
    }

    protected function renderBodyEndHtml($ajaxMode)
    {
        return $this->withNonce(parent::renderBodyEndHtml($ajaxMode));
    }

    /** Puts nonce="…" on every inline <script> tag in $html that has none */
    public function withNonce(string $html): string
    {
        if ($html === '' || !Yii::$app->has('csp')) {
            return $html;
        }
        $nonce = Html::encode(Yii::$app->get('csp')->nonce());
        return (string) preg_replace('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce=)(?=[\s>])/i', '<script nonce="' . $nonce . '"', $html);
    }
}
