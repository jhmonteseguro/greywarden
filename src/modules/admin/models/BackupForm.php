<?php

namespace app\modules\admin\models;

use app\models\AuditLog;
use app\models\Setting;
use yii\base\Model;

/**
 * Admin › Settings › Backups: the directory scripts/backup.sh writes to (and
 * so scripts/deploy.sh, which backs up first). It is a path on the host that
 * runs the script, not inside a container: absolute, ~/ for the home of the
 * user running it, or relative to the Greywarden checkout. Spaces are fine.
 * Empty means the default, backups/. BACKUP_DIR set when the script runs
 * still takes precedence. Changes go to the audit log (entity "setting").
 */
class BackupForm extends Model
{
    /** Setting read by scripts/backup.sh (keep the two in step) */
    public const SETTING_PATH = 'backup.path';
    /** Used when nothing is set, relative to the checkout */
    public const DEFAULT_PATH = 'backups';
    public const MAX_LENGTH = 1024;

    public $backup_path;

    /** The stored directory, '' when the default is used */
    public static function path(): string
    {
        return (string) Setting::get(self::SETTING_PATH, '');
    }

    public static function current(): self
    {
        return new self(['backup_path' => self::path()]);
    }

    public function rules(): array
    {
        return [
            // Only the ends are trimmed: spaces inside directory names are kept
            ['backup_path', 'filter', 'filter' => static fn($v) => trim((string) $v)],
            ['backup_path', 'string', 'max' => self::MAX_LENGTH],
            // Control characters (newlines, tabs, NUL) never belong in a path and would not survive the script reading it
            ['backup_path', 'match', 'not' => true, 'pattern' => '/[\x{0000}-\x{001f}\x{007f}]/', 'message' => '{attribute} cannot contain control characters such as tabs or line breaks.'],
            ['backup_path', 'match', 'pattern' => '/[^\/]/', 'message' => '{attribute} cannot be the root directory.'],
        ];
    }

    public function attributeLabels(): array
    {
        return ['backup_path' => 'Backup directory'];
    }

    public function attributeHints(): array
    {
        return ['backup_path' => 'Where scripts/backup.sh (and make backup, scripts/deploy.sh) writes database and attachment backups, on the host that runs it. '
            . 'Use an absolute path such as /mnt/nas/Greywarden backups (spaces are fine), ~/ for the home directory of the user running the script, '
            . 'or a path relative to the Greywarden checkout. It is created if missing. Leave empty for the default, ' . self::DEFAULT_PATH . '/. '
            . 'BACKUP_DIR set when the script runs takes precedence.'];
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $before = self::path();
        $value = (string) $this->backup_path;
        if ($value !== $before) {
            Setting::put([self::SETTING_PATH => $value]);
            AuditLog::record('setting', null, 'Backups', 'update', ['backup_path' => [$before, $value]]);
        }
        return true;
    }
}
