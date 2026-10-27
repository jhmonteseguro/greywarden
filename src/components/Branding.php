<?php

namespace app\components;

use app\models\Setting;
use Yii;
use yii\base\BootstrapInterface;
use yii\helpers\FileHelper;
use yii\helpers\Url;
use yii\web\UploadedFile;

/**
 * How this instance presents itself: display name, organisation, logo,
 * sign-in page text and the PDF marking. Set by admins in Admin › Settings
 * (stored in the `setting` table); nothing organisation-specific lives in the
 * code or config.
 *
 * As a bootstrap component of the web application (config/web.php) it puts the
 * display name into Yii::$app->name, which the layouts and page titles already use.
 *
 * The logo is re-encoded with GD on upload (dropping metadata and anything
 * appended to the image) and stored outside the web root under
 * params[branding.path]; SiteController::actionLogo serves it.
 */
final class Branding implements BootstrapInterface
{
    public const DEFAULT_TAGLINE = 'Security operations console';
    public const DEFAULT_LOGIN_NOTICE = 'Restricted system · all access is monitored and audited';
    public const DEFAULT_CLASSIFICATION = 'Confidential';

    public const LOGO_MAX_KB = 512;
    public const LOGO_MIN_PX = 16;
    public const LOGO_MAX_PX = 1024;
    /** getimagesize() type => [extension, MIME type] */
    private const LOGO_TYPES = [IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_JPEG => ['jpg', 'image/jpeg']];
    private const LOGO_NAME = '/^logo-[0-9a-f]{12}\.(png|jpg)$/';

    /** Start of each web request: settings may have changed since the last one */
    public function bootstrap($app): void
    {
        Setting::reset();
        $app->name = self::appName();
    }

    /** The product name (APP_NAME, default Greywarden), used when no display name is set */
    public static function productName(): string
    {
        return (string) (Yii::$app->params['appName'] ?? 'Greywarden');
    }

    public static function appName(): string
    {
        $name = self::text('app_name');
        return $name !== null && $name !== '' ? $name : self::productName();
    }

    /** Organisation name for the PDF letterhead; '' when not set */
    public static function organisation(): string
    {
        return self::text('organisation') ?? '';
    }

    /** Line under the name on the sign-in page; '' hides it */
    public static function tagline(): string
    {
        return self::text('tagline') ?? self::DEFAULT_TAGLINE;
    }

    /** Notice at the foot of the sign-in page; '' hides it */
    public static function loginNotice(): string
    {
        return self::text('login_notice') ?? self::DEFAULT_LOGIN_NOTICE;
    }

    /** Marking printed on every PDF page; '' prints none */
    public static function classification(): string
    {
        return self::text('pdf_classification') ?? self::DEFAULT_CLASSIFICATION;
    }

    /** Trimmed stored value, or null when the setting was never saved */
    private static function text(string $name): ?string
    {
        $values = Setting::values();
        return array_key_exists($name, $values) ? trim((string) $values[$name]) : null;
    }

    // ---- Logo ---------------------------------------------------------------

    public static function storagePath(): string
    {
        return rtrim(Yii::getAlias(Yii::$app->params['branding.path']), '/');
    }

    /** Stored file name of the uploaded logo, or null when there is none (or its file is missing) */
    public static function logoName(): ?string
    {
        $name = (string) Setting::get('logo');
        return preg_match(self::LOGO_NAME, $name) && is_file(self::storagePath() . '/' . $name) ? $name : null;
    }

    public static function logoPath(): ?string
    {
        $name = self::logoName();
        return $name ? self::storagePath() . '/' . $name : null;
    }

    public static function logoMimeType(): ?string
    {
        $name = self::logoName();
        return $name ? (str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg') : null;
    }

    /** URL of the logo; the file name changes with the content, so it can be cached for good */
    public static function logoUrl(): ?string
    {
        $name = self::logoName();
        return $name ? Url::to(['/site/logo', 'v' => substr($name, 5, 12)]) : null;
    }

    /** The logo inlined for dompdf, which never fetches URLs */
    public static function logoDataUri(): ?string
    {
        $path = self::logoPath();
        return $path ? 'data:' . self::logoMimeType() . ';base64,' . base64_encode((string) file_get_contents($path)) : null;
    }

    /** Problem with an uploaded logo, or null when it can be used */
    public static function checkLogo(UploadedFile $file): ?string
    {
        if (in_array($file->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file->size > self::LOGO_MAX_KB * 1024) {
            return sprintf('The logo must be %d KB or smaller.', self::LOGO_MAX_KB);
        }
        if ($file->error !== UPLOAD_ERR_OK || !is_file($file->tempName)) {
            return 'The logo did not upload completely. Try again.';
        }
        $info = @getimagesize($file->tempName);
        if (!$info || !isset(self::LOGO_TYPES[$info[2]])) {
            return 'The logo must be a PNG or JPEG image.';
        }
        [$width, $height] = $info;
        if (min($width, $height) < self::LOGO_MIN_PX || max($width, $height) > self::LOGO_MAX_PX) {
            return sprintf('The logo must be between %d and %d pixels on each side (this one is %d × %d).',
                self::LOGO_MIN_PX, self::LOGO_MAX_PX, $width, $height);
        }
        return null;
    }

    /**
     * Re-encodes a checked image into the branding folder.
     * @return string the stored file name (save it as the `logo` setting)
     */
    public static function storeLogo(string $sourcePath): string
    {
        $info = @getimagesize($sourcePath);
        $image = $info && isset(self::LOGO_TYPES[$info[2]]) ? @imagecreatefromstring((string) file_get_contents($sourcePath)) : false;
        if (!$image) {
            throw new \RuntimeException('The logo could not be read as an image.');
        }
        [$ext] = self::LOGO_TYPES[$info[2]];
        FileHelper::createDirectory(self::storagePath(), 0750);
        $tmp = self::storagePath() . '/.upload-' . bin2hex(random_bytes(6));
        if ($ext === 'png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $written = @imagepng($image, $tmp, 9);
        } else {
            $written = @imagejpeg($image, $tmp, 90);
        }
        imagedestroy($image);
        if (!$written) {
            @unlink($tmp);
            throw new \RuntimeException('The logo could not be stored. Ask an administrator to check the branding folder.');
        }
        $name = 'logo-' . substr(hash_file('sha256', $tmp), 0, 12) . '.' . $ext;
        rename($tmp, self::storagePath() . '/' . $name);
        @chmod(self::storagePath() . '/' . $name, 0640);
        return $name;
    }

    /** Removes a logo file that is no longer in use */
    public static function deleteLogoFile(?string $name): void
    {
        if ($name && preg_match(self::LOGO_NAME, $name) && $name !== Setting::get('logo')) {
            @unlink(self::storagePath() . '/' . $name);
        }
    }
}
