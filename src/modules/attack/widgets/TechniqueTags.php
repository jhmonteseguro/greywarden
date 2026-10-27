<?php

namespace app\modules\attack\widgets;

use app\helpers\Badge;
use app\modules\attack\models\AttackMitigation;
use app\modules\attack\models\AttackTechnique;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * ATT&CK id chips linking to the technique / mitigation pages.
 *
 * Markup: span.gw-attack-tags > a.gw-attack-tag(.is-deprecated) > span.gw-attack-tag__id + span.gw-attack-tag__name
 *
 *   TechniqueTags::widget(['items' => $incident->techniques])                      // id + name
 *   TechniqueTags::widget(['items' => $incident->techniques, 'compact' => true, 'max' => 3])  // ids only (grids)
 */
class TechniqueTags extends Widget
{
    /** @var AttackTechnique[]|AttackMitigation[] */
    public array $items = [];
    /** ids only, name in the tooltip */
    public bool $compact = false;
    /** show at most this many, then "+N" (0 = all) */
    public int $max = 0;
    public string $emptyText = '';

    public function run(): string
    {
        if (!$this->items) {
            return $this->emptyText === '' ? '' : Html::tag('span', Html::encode($this->emptyText), ['class' => 'gw-muted gw-attack-tags__empty']);
        }
        $items = $this->items;
        usort($items, static fn($a, $b) => strnatcmp($a->external_id, $b->external_id));
        $shown = $this->max > 0 ? array_slice($items, 0, $this->max) : $items;
        $html = [];
        foreach ($shown as $item) {
            $html[] = self::tag($item, $this->compact);
        }
        if (count($items) > count($shown)) {
            $rest = array_slice($items, count($shown));
            $html[] = Html::tag('span', '+' . count($rest), [
                'class' => 'gw-attack-tags__more',
                'title' => implode(', ', array_map(static fn($i) => $i->external_id, $rest)),
            ]);
        }
        return Html::tag('span', implode('', $html), ['class' => 'gw-attack-tags' . ($this->compact ? ' gw-attack-tags--compact' : '')]);
    }

    public static function tag(AttackTechnique|AttackMitigation $item, bool $compact = false): string
    {
        $name = $item->getTitleValue();
        $label = Html::tag('span', Html::encode($item->external_id), ['class' => 'gw-attack-tag__id'])
            . ($compact ? '' : Html::tag('span', Html::encode($name), ['class' => 'gw-attack-tag__name']));
        if ($item->is_deprecated && !$compact) {
            $label .= ' ' . Badge::flag('deprecated', 'Deprecated', $item instanceof AttackTechnique && $item->revoked_by ? 'Replaced by ' . $item->revoked_by : null);
        }
        return Html::a($label, $item->getViewUrl(), [
            'class' => 'gw-attack-tag' . ($item instanceof AttackMitigation ? ' gw-attack-tag--mitigation' : '') . ($item->is_deprecated ? ' is-deprecated' : ''),
            'title' => $compact ? $item->external_id . ' · ' . $name : null,
            'data-bs-toggle' => $compact ? 'tooltip' : null,
        ]);
    }
}
