<?php

namespace app\modules\admin\models;

use app\models\AuditLog;
use app\models\Setting;
use app\modules\incident\components\IncidentWording;
use app\modules\incident\models\Incident;
use yii\base\Model;

/**
 * Admin › Settings › Incident reports: the plain-English meaning printed next to
 * each status and severity in incident PDF reports. One attribute per status and
 * severity ("status_contained", "severity_high"); a blank or default text is
 * stored empty, so it keeps following the built-in default. Changes go to the
 * audit log (entity "setting").
 */
class IncidentReportForm extends Model
{
    public const MAX_LENGTH = 500;

    /** @var array<string,string|null> attribute => text */
    private array $texts = [];

    /** The form as currently saved: the wording in force, defaults included */
    public static function current(): self
    {
        $form = new self();
        foreach (self::wordingFields() as $attribute => [$kind, $key]) {
            $form->texts[$attribute] = IncidentWording::text($kind, $key);
        }
        return $form;
    }

    /** @return array<string,array{string,string}> attribute => [kind, key] */
    public static function wordingFields(): array
    {
        $fields = [];
        foreach (IncidentWording::defaults() as $kind => $defaults) {
            foreach (array_keys($defaults) as $key) {
                $fields["{$kind}_{$key}"] = [$kind, $key];
            }
        }
        return $fields;
    }

    /** @return string[] attribute names for one kind, in lifecycle / severity order */
    public static function attributesOf(string $kind): array
    {
        return array_keys(array_filter(self::wordingFields(), static fn(array $field) => $field[0] === $kind));
    }

    public function attributes(): array
    {
        return array_keys(self::wordingFields());
    }

    public function __get($name)
    {
        return array_key_exists($name, self::wordingFields()) ? ($this->texts[$name] ?? null) : parent::__get($name);
    }

    public function __set($name, $value)
    {
        if (array_key_exists($name, self::wordingFields())) {
            $this->texts[$name] = $value;
            return;
        }
        parent::__set($name, $value);
    }

    public function __isset($name)
    {
        return array_key_exists($name, self::wordingFields()) ? isset($this->texts[$name]) : parent::__isset($name);
    }

    public function rules(): array
    {
        return [
            [$this->attributes(), 'trim'],
            [$this->attributes(), 'string', 'max' => self::MAX_LENGTH],
        ];
    }

    public function attributeLabels(): array
    {
        $labels = [];
        foreach (self::wordingFields() as $attribute => [$kind, $key]) {
            $options = $kind === IncidentWording::KIND_STATUS ? Incident::statusOptions() : Incident::severityOptions();
            $labels[$attribute] = $options[$key];
        }
        return $labels;
    }

    /** Audit entries label each text with its kind: "Status: Contained" */
    public static function auditLabels(): array
    {
        $labels = [];
        foreach ((new self())->attributeLabels() as $attribute => $label) {
            $labels[$attribute] = ucfirst(self::wordingFields()[$attribute][0]) . ': ' . $label;
        }
        return $labels;
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $before = self::current();

        $values = [];
        $changes = [];
        foreach (self::wordingFields() as $attribute => [$kind, $key]) {
            $default = IncidentWording::defaults()[$kind][$key];
            $text = (string) $this->$attribute;
            $effective = $text === '' ? $default : $text;
            if ($effective !== $before->$attribute) {
                // Stored empty when it matches the default, so later default wording still applies
                $values[IncidentWording::settingName($kind, $key)] = $effective === $default ? '' : $effective;
                $changes[$attribute] = [$before->$attribute, $effective];
            }
        }

        if ($values) {
            Setting::put($values);
            AuditLog::record('setting', null, 'Incident report wording', 'update', $changes);
        }
        return true;
    }
}
