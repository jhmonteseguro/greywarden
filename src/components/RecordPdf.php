<?php

namespace app\components;

use app\models\AuditLog;
use app\models\DomainRecord;
use Dompdf\Dompdf;
use Dompdf\Options;
use Yii;
use yii\helpers\FileHelper;
use yii\helpers\Inflector;
use yii\web\Response;
use yii\web\View;

/**
 * Renders a record as a printable PDF (risk, control, decision) for sending to
 * management, DevOps or auditors.
 *
 * The module's `pdf` view renders the body; @app/views/pdf/document wraps it in
 * the letterhead and print stylesheet. Every export is written to the record's
 * History as "Exported to PDF", so there is a trail of what was sent out.
 */
final class RecordPdf
{
    /** A4 portrait, in points */
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    /** @param string $bodyView path of the module's pdf view file */
    public static function send(View $view, string $bodyView, DomainRecord $model): Response
    {
        $pdf = self::render($view, $bodyView, $model);
        AuditLog::record($model::entityType(), $model->id, $model->getAuditLabel(), 'export', ['format' => [null, 'PDF']]);

        return Yii::$app->response->sendContentAsFile($pdf, self::filename($model), [
            'mimeType' => 'application/pdf',
            'inline' => true,
        ]);
    }

    /** @return string the PDF bytes */
    public static function render(View $view, string $bodyView, DomainRecord $model): string
    {
        $generatedAt = Yii::$app->formatter->asDatetime(time());
        $generatedBy = self::generatedBy();

        $dompdf = new Dompdf(self::options());
        $dompdf->loadHtml(self::html($view, $bodyView, $model, $generatedAt, $generatedBy), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        self::footer($dompdf, implode('  ·  ', array_filter([
            $model->getReferenceValue(), Branding::classification(), "Generated $generatedAt by $generatedBy"], 'strlen')));

        return (string) $dompdf->output();
    }

    /** The HTML handed to dompdf: the module's pdf view wrapped in @app/views/pdf/document */
    public static function html(View $view, string $bodyView, DomainRecord $model, string $generatedAt, string $generatedBy): string
    {
        return $view->render('@app/views/pdf/document', [
            'model' => $model,
            'content' => $view->renderFile($bodyView, ['model' => $model]),
            'generatedAt' => $generatedAt,
            'generatedBy' => $generatedBy,
        ]);
    }

    /** e.g. "RSK-0017-critical-vulnerability-in-internet-facing-software.pdf" */
    public static function filename(DomainRecord $model): string
    {
        $slug = mb_substr(Inflector::slug($model->getTitleValue()), 0, 60);
        return rtrim($model->getReferenceValue() . '-' . rtrim($slug, '-'), '-') . '.pdf';
    }

    private static function generatedBy(): string
    {
        if (Yii::$app->has('user') && !Yii::$app->user->isGuest) {
            return Yii::$app->user->identity->full_name ?: Actor::name();
        }
        return Actor::name();
    }

    private static function options(): Options
    {
        $cache = Yii::getAlias('@runtime/dompdf');
        FileHelper::createDirectory($cache);

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');   // bundled; covers ·, —, × and accented names
        $options->setIsRemoteEnabled(false);       // never fetch URLs found in record text
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([Yii::getAlias('@app/views/pdf')]);
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        return $options;
    }

    private static function footer(Dompdf $dompdf, string $text): void
    {
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $grey = [0.42, 0.45, 0.5];
        $y = self::PAGE_HEIGHT - 30;
        $canvas->page_text(45, $y, $text, $font, 7, $grey);
        $canvas->page_text(self::PAGE_WIDTH - 95, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, $grey);
    }
}
