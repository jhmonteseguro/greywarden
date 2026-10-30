<?php

namespace app\modules\admin\models;

use app\components\Branding;
use app\models\AuditLog;
use app\models\Setting;
use yii\base\Model;
use yii\web\UploadedFile;

/**
 * Admin › Settings › Branding. Saves to the `setting` table and writes the
 * changed fields to the audit log (entity "setting").
 */
class BrandingForm extends Model
{
    /** Text settings edited by this form */
    public const FIELDS = ['app_name', 'organisation', 'tagline', 'login_notice', 'pdf_classification'];

    public $app_name;
    public $organisation;
    public $tagline;
    public $login_notice;
    public $pdf_classification;
    /** @var UploadedFile|null */
    public $logoFile;
    public $remove_logo = false;

    /** The form as currently saved (defaults filled in, so admins see what is shown) */
    public static function current(): self
    {
        return new self([
            'app_name' => Branding::appName() === Branding::productName() ? '' : Branding::appName(),
            'organisation' => Branding::organisation(),
            'tagline' => Branding::tagline(),
            'login_notice' => Branding::loginNotice(),
            'pdf_classification' => Branding::classification(),
        ]);
    }

    public function rules(): array
    {
        return [
            [self::FIELDS, 'trim'],
            [self::FIELDS, 'default', 'value' => ''],
            ['app_name', 'string', 'max' => 40],
            ['organisation', 'string', 'max' => 120],
            ['tagline', 'string', 'max' => 80],
            ['login_notice', 'string', 'max' => 200],
            ['pdf_classification', 'string', 'max' => 40],
            ['remove_logo', 'boolean'],
            ['logoFile', 'validateLogo', 'skipOnEmpty' => false],
        ];
    }

    public function validateLogo(string $attribute): void
    {
        $file = $this->$attribute;
        if ($file instanceof UploadedFile && $file->error !== UPLOAD_ERR_NO_FILE && ($problem = Branding::checkLogo($file))) {
            $this->addError($attribute, $problem);
        }
    }

    /** Also labels the compliance, incident report and edge monitoring settings: the audit log renders every "setting" entry through this form */
    public function attributeLabels(): array
    {
        return (new ComplianceForm())->attributeLabels() + IncidentReportForm::auditLabels() + (new EdgeForm())->attributeLabels() + (new ReportForm())->attributeLabels() + [
            'app_name' => 'Display name',
            'organisation' => 'Organisation',
            'tagline' => 'Sign-in tagline',
            'login_notice' => 'Sign-in notice',
            'pdf_classification' => 'PDF marking',
            'logoFile' => 'Logo',
            'logo' => 'Logo',
            'remove_logo' => 'Remove the logo (use the built-in mark)',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'app_name' => sprintf('Shown in the sidebar, on the sign-in page and in browser tabs. Leave blank for “%s”.', Branding::productName()),
            'organisation' => 'Printed on the letterhead of exported PDFs. Leave blank to leave it off.',
            'tagline' => 'Under the name on the sign-in page. Leave blank to hide it.',
            'login_notice' => 'At the foot of the sign-in page, e.g. an acceptable-use warning. Leave blank to hide it.',
            'pdf_classification' => 'Protective marking on every page of exported PDFs, e.g. Confidential or OFFICIAL-SENSITIVE. Leave blank for none.',
            'logoFile' => sprintf('PNG or JPEG, square works best, %d–%d px, up to %d KB. Shown in the sidebar, on the sign-in page, as the browser icon and on PDFs.',
                Branding::LOGO_MIN_PX, Branding::LOGO_MAX_PX, Branding::LOGO_MAX_KB),
        ];
    }

    public function load($data, $formName = null): bool
    {
        $loaded = parent::load($data, $formName);
        $this->logoFile = UploadedFile::getInstance($this, 'logoFile');
        return $loaded || $this->logoFile !== null;
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $before = self::current();
        $oldLogo = Branding::logoName();

        $values = [];
        $changes = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = (string) $this->$field;
            if ($values[$field] !== (string) $before->$field) {
                $changes[$field] = [$before->$field, $values[$field]];
            }
        }

        $newLogo = $oldLogo;
        if ($this->logoFile instanceof UploadedFile && $this->logoFile->error !== UPLOAD_ERR_NO_FILE) {
            try {
                $newLogo = Branding::storeLogo($this->logoFile->tempName);
            } catch (\RuntimeException $e) {
                $this->addError('logoFile', $e->getMessage());
                return false;
            }
        } elseif ($this->remove_logo) {
            $newLogo = null;
        }
        if ($newLogo !== $oldLogo) {
            $values['logo'] = $newLogo;
            $changes['logo'] = [$oldLogo, $newLogo];
        }

        Setting::put(array_intersect_key($values, $changes));   // untouched settings keep following the defaults
        if ($oldLogo !== $newLogo) {
            Branding::deleteLogoFile($oldLogo);
        }
        if ($changes) {
            AuditLog::record('setting', null, 'Branding', 'update', $changes);
        }
        return true;
    }
}
