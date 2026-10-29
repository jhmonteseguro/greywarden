<?php

namespace app\modules\admin\models;

use app\models\AuditLog;
use app\models\Setting;
use app\modules\intel\components\edge\EdgeSettings;
use yii\base\Model;

/**
 * Admin › Settings › Edge monitoring: what edge findings ignore and when they
 * open (see EdgeSettings). Changes go to the audit log (entity "setting").
 */
class EdgeForm extends Model
{
    public const MAX_LIST = 5000;

    public $ignore_paths = '';
    public $allowlist = '';
    public $min_score;
    public $block_days;
    public $volume_rpm;

    public static function current(): self
    {
        $form = new self();
        foreach (array_keys(EdgeSettings::DEFAULTS) as $key) {
            $form->$key = EdgeSettings::get($key);
        }
        return $form;
    }

    public function rules(): array
    {
        return [
            [['ignore_paths', 'allowlist'], 'trim'],
            [['ignore_paths', 'allowlist'], 'string', 'max' => self::MAX_LIST],
            [['min_score', 'block_days', 'volume_rpm'], 'required'],
            ['min_score', 'integer', 'min' => 1, 'max' => 99],
            ['block_days', 'integer', 'min' => 1, 'max' => 365],
            ['volume_rpm', 'integer', 'min' => 1, 'max' => 100000],
            ['allowlist', 'validateAllowlist'],
            ['ignore_paths', 'validatePaths'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'ignore_paths' => 'Paths your sites serve',
            'allowlist' => 'Never flag',
            'min_score' => 'Open a finding from score',
            'block_days' => 'Default block length (days)',
            'volume_rpm' => 'Flood threshold (requests a minute)',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'ignore_paths' => 'One path prefix per line, e.g. /wp-admin on a WordPress site. Requests under these never count as probes, and the suggested WAF rule leaves them out.',
            'allowlist' => 'One address, network (CIDR) or ASN per line: your offices, uptime monitors, partners. Lines starting with # are comments.',
            'min_score' => 'Lower opens more findings. 30 catches a single request for a known attack path.',
            'block_days' => 'Offered first when blocking from a finding. Expired blocks are taken out of Cloudflare on the next pull.',
            'volume_rpm' => 'Requests a minute from one address, after what Cloudflare stopped, that count as a flood.',
        ];
    }

    public function validateAllowlist(string $attribute): void
    {
        $invalid = EdgeSettings::allowlist((string) $this->$attribute)['invalid'];
        if ($invalid) {
            $this->addError($attribute, 'Not an address, network or ASN: ' . implode(', ', array_slice($invalid, 0, 5)) . '.');
        }
    }

    public function validatePaths(string $attribute): void
    {
        $bad = array_filter(EdgeSettings::lines((string) $this->$attribute), static fn(string $line) => !str_starts_with($line, '/') || preg_match('/\s/', $line));
        if ($bad) {
            $this->addError($attribute, 'Each line must be a path starting with "/": ' . implode(', ', array_slice($bad, 0, 5)) . '.');
        }
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $before = self::current();
        $values = [];
        $changes = [];
        foreach (array_keys(EdgeSettings::DEFAULTS) as $key) {
            $value = (string) $this->$key;
            if ($value !== (string) $before->$key) {
                $values[EdgeSettings::settingName($key)] = $value === EdgeSettings::DEFAULTS[$key] ? '' : $value;
                $changes[$key] = [$before->$key, $value];
            }
        }
        if ($values) {
            Setting::put($values);
            AuditLog::record('setting', null, 'Edge monitoring', 'update', $changes);
        }
        return true;
    }
}
