<?php

namespace app\modules\attack\components;

use yii\helpers\Html;

/**
 * Renders ATT&CK description text (plain paragraphs with the occasional
 * <code> span and "* " bullet) as safe HTML.
 */
final class AttackText
{
    public static function render(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $html = [];
        foreach (preg_split("/\n{2,}/", $text) as $block) {
            $lines = array_map('trim', explode("\n", trim($block)));
            if (count(array_filter($lines, static fn($l) => preg_match('/^[*-] /', $l))) === count($lines)) {
                $items = array_map(static fn($l) => '<li>' . self::inline(substr($l, 2)) . '</li>', $lines);
                $html[] = '<ul>' . implode('', $items) . '</ul>';
            } else {
                $html[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $lines)) . '</p>';
            }
        }
        return implode("\n", $html);
    }

    /** First paragraph only, as plain text (for tooltips and summaries). */
    public static function summary(?string $text, int $max = 300): string
    {
        $first = trim(preg_split("/\n{2,}/", trim((string) $text))[0] ?? '');
        $first = strip_tags($first);
        return mb_strlen($first) > $max ? rtrim(mb_substr($first, 0, $max - 1)) . '…' : $first;
    }

    private static function inline(string $line): string
    {
        $html = Html::encode($line);
        // MITRE wraps commands and paths in <code>; keep those, nothing else
        return preg_replace('#&lt;code&gt;(.*?)&lt;/code&gt;#s', '<code class="gw-code">$1</code>', $html);
    }
}
