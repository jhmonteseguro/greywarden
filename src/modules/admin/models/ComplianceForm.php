<?php

namespace app\modules\admin\models;

use app\models\AuditLog;
use app\models\Setting;
use app\modules\control\components\FrameworkCatalogue;
use yii\base\Model;

/**
 * Admin › Settings › Compliance frameworks: which frameworks are switched on,
 * and the SOC 2 Trust Services categories in scope (Security always is).
 * Saves to the `setting` table and writes the changes to the audit log
 * (entity "setting").
 *
 * Switching a framework off only hides it: requirement applicability and
 * control links are kept for when it is switched back on.
 */
class ComplianceForm extends Model
{
    /** @var string[] frameworks switched on */
    public $frameworks = [];
    /** @var string[] optional SOC 2 categories in scope */
    public $soc2_categories = [];

    /** The form as currently saved */
    public static function current(): self
    {
        return new self([
            'frameworks' => FrameworkCatalogue::enabled(),
            'soc2_categories' => array_values(array_diff(FrameworkCatalogue::soc2Scope(), [FrameworkCatalogue::SOC2_SECURITY])),
        ]);
    }

    public function rules(): array
    {
        return [
            [['frameworks', 'soc2_categories'], 'default', 'value' => []],
            ['frameworks', 'each', 'rule' => ['in', 'range' => FrameworkCatalogue::frameworks()]],
            ['soc2_categories', 'each', 'rule' => ['in', 'range' => array_keys(FrameworkCatalogue::soc2OptionalCategories())]],
        ];
    }

    public function attributeLabels(): array
    {
        $labels = [
            'frameworks' => 'Frameworks',
            'soc2_categories' => 'SOC 2 categories in scope',
        ];
        // Audit entries record each framework under its own key
        foreach (FrameworkCatalogue::frameworks() as $framework) {
            $labels[$framework] = FrameworkCatalogue::label($framework);
        }
        return $labels;
    }

    public function attributeHints(): array
    {
        return [
            'frameworks' => 'A framework switched off disappears from the sidebar, dashboard, search and control forms. '
                . 'Its applicability decisions and control mappings are kept for when it is switched back on.',
            'soc2_categories' => 'Security (the common criteria) is always in scope. Add the categories your SOC 2 report covers.',
        ];
    }

    /** @return array<string,string> framework => label, for the switches */
    public static function frameworkOptions(): array
    {
        $options = [];
        foreach (FrameworkCatalogue::frameworks() as $framework) {
            $options[$framework] = FrameworkCatalogue::label($framework);
        }
        return $options;
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $before = self::current();

        $values = [];
        $changes = [];
        foreach (FrameworkCatalogue::frameworks() as $framework) {
            $on = in_array($framework, $this->frameworks, true);
            if ($on !== in_array($framework, $before->frameworks, true)) {
                $values[FrameworkCatalogue::settingName($framework)] = $on ? '1' : '0';
                $changes[$framework] = [$on ? 'Off' : 'On', $on ? 'On' : 'Off'];
            }
        }

        // Catalogue order, so the stored value only changes when the choice does
        $categories = array_keys(array_intersect_key(FrameworkCatalogue::soc2OptionalCategories(), array_flip($this->soc2_categories)));
        $previous = array_keys(array_intersect_key(FrameworkCatalogue::soc2OptionalCategories(), array_flip($before->soc2_categories)));
        if ($categories !== $previous) {
            $values[FrameworkCatalogue::SETTING_SOC2_CATEGORIES] = implode(',', $categories);
            $changes['soc2_categories'] = [self::categoryText($previous), self::categoryText($categories)];
        }

        if ($values) {
            Setting::put($values);
            AuditLog::record('setting', null, 'Compliance frameworks', 'update', $changes);
        }
        return true;
    }

    /** "Security, Availability" */
    private static function categoryText(array $optional): string
    {
        return implode(', ', array_intersect_key(FrameworkCatalogue::soc2Categories(), array_flip(array_merge([FrameworkCatalogue::SOC2_SECURITY], $optional))));
    }
}
