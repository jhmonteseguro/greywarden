<?php

namespace app\modules\threat\components;

use app\modules\attack\models\AttackGroup;
use Yii;
use yii\db\Query;

/**
 * Finds the threat groups an article names, by the names MITRE ATT&CK lists for them.
 *
 * Names are matched as written, so "Sea Turtle" counts and "sea turtle" does not. Names that
 * are also everyday words or products ("Play", "Silence", "Equation") only count beside a
 * word that shows a group is meant: "Equation Group", "Silence hackers", "the threat actor
 * Tick". A wrong tag sends a reader to the wrong actor, so an unclear mention is left out.
 *
 * Roundups are skipped: they name many groups and are about none of them.
 */
final class ActorTagger
{
    /** Also everyday words, products or other things in security news; compared without case */
    public const NEEDS_CONTEXT = [
        'Actinium', 'Americium', 'Armageddon', 'Axiom', 'Barium', 'Beijing Group', 'Bismuth', 'Bromine', 'Buckeye', 'Callisto', 'Chimera', 'Chromium',
        'Cicada', 'Cleaver', 'Comment Group', 'Confucius', 'Dragonfly', 'Electrum', 'Elfin', 'Equation', 'Gallium', 'Group 72', 'Group 74', 'Group 88',
        'Inception', 'Iridium', 'Karma', 'Krypton', 'Lead', 'Leviathan', 'Machete', 'Manganese', 'Mantis', 'Medusa', 'Mercury', 'Mirage', 'Monsoon',
        'Newscaster', 'Nickel', 'Patchwork', 'Phosphorus', 'Platinum', 'Play', 'Potassium', 'Radium', 'Rancor', 'Rattlesnake', 'Reaper', 'RTM',
        'Silence', 'Silicon', 'Snake', 'Strider', 'TAAL', 'Tick', 'UPS Team', 'Zinc',
    ];

    /** Ransomware brands that are these groups, unlike "Snake ransomware" or "Karma ransomware" */
    public const RANSOMWARE_BRANDS = ['Medusa', 'Play'];

    /** How news shortens these names; the short name then counts as one of the group's */
    public const SHORT_NAMES = [
        'Gamaredon Group' => 'Gamaredon',
        'Lazarus Group' => 'Lazarus',
        'Medusa Group' => 'Medusa',
        'Sandworm Team' => 'Sandworm',
        'Tonto Team' => 'Tonto',
        'Winnti Group' => 'Winnti',
    ];

    private const BEFORE = '(?<![\p{L}\p{N}_.@$])';
    private const AFTER = '(?![\p{L}\p{N}_$@])';
    private const WORD_AFTER = '\s+(?i:group|gang|crew|hackers?|APT|actors?|threat actors?|attackers?)\b';
    private const WORD_BEFORE = '(?i:threat (?:actor|group)|hack(?:ing|er) group|ransomware group|APT group)\s+';

    /** PCRE refuses a pattern past 64 KB compiled; 150 names stays well inside it */
    private const NAMES_PER_PATTERN = 150;
    private const BATCH = 500;

    /** @var array<string, int[]> name => groups */
    private array $groups = [];
    /** @var string[] */
    private array $patterns = [];

    /** @param array<int, string[]> $names group id => every name to look for */
    public function __construct(array $names)
    {
        $plain = [];
        $unclear = [];
        $needsContext = array_flip(array_map('mb_strtolower', self::NEEDS_CONTEXT));
        foreach ($names as $groupId => $list) {
            foreach ($list as $name) {
                $this->groups[$name][] = $groupId;
                if (isset($needsContext[mb_strtolower($name)])) {
                    $unclear[$name] = true;
                } else {
                    $plain[$name] = true;
                }
            }
        }
        foreach ($this->alternatives(array_map('strval', array_keys($plain))) as $names) {
            $this->patterns[] = '/' . self::BEFORE . "($names)" . self::AFTER . '/u';
        }
        foreach ($this->alternatives(array_map('strval', array_keys($unclear))) as $names) {
            $this->patterns[] = '/' . self::BEFORE . "($names)" . self::WORD_AFTER . '/u';
            $this->patterns[] = '/' . self::WORD_BEFORE . "($names)" . self::AFTER . '/u';
        }
        $brands = array_values(array_intersect(self::RANSOMWARE_BRANDS, array_keys($unclear)));
        foreach ($this->alternatives($brands) as $names) {
            $this->patterns[] = '/' . self::BEFORE . "($names)" . '\s+(?i:ransomware)\b/u';
        }
    }

    /** The current catalogue's groups */
    public static function fromCatalogue(): self
    {
        $names = [];
        foreach (AttackGroup::find()->where(['is_deprecated' => 0])->all() as $group) {
            $names[$group->id] = self::namesOf($group->name, $group->getAliasList());
        }
        return new self($names);
    }

    /**
     * Every way news writes a group's name. Vendors' capitals are also written as a name
     * ("BRONZE BUTLER", "Bronze Butler").
     * @return string[]
     */
    public static function namesOf(string $name, array $aliases): array
    {
        $names = [];
        foreach ([$name, ...$aliases] as $alias) {
            $alias = trim((string) $alias);
            if (mb_strlen($alias) < 3) {
                continue;
            }
            $names[$alias] = true;
            if (isset(self::SHORT_NAMES[$alias])) {
                $names[self::SHORT_NAMES[$alias]] = true;
            }
            if (str_contains($alias, ' ') && $alias === mb_strtoupper($alias)) {
                $names[mb_convert_case($alias, MB_CASE_TITLE)] = true;
            }
        }
        return array_map('strval', array_keys($names));
    }

    /** @return int[] ids of the groups the text names */
    public function find(string $text): array
    {
        $found = [];
        foreach ($this->patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach (array_unique($matches[1]) as $name) {
                    foreach ($this->groups[$name] as $groupId) {
                        $found[$groupId] = true;
                    }
                }
            }
        }
        return array_keys($found);
    }

    /**
     * Reads the articles not read yet.
     * @return int how many mentions were recorded
     */
    public static function run(): int
    {
        $db = Yii::$app->db;
        $tagger = null;
        $tagged = 0;
        while ($rows = self::unread()) {
            $tagger ??= self::fromCatalogue();
            $links = [];
            foreach ($rows as $row) {
                if (!$row['is_roundup']) {
                    foreach ($tagger->find($row['title'] . "\n" . $row['excerpt']) as $groupId) {
                        $links[] = [(int) $row['id'], $groupId];
                    }
                }
            }
            $ids = array_map('intval', array_column($rows, 'id'));
            $tx = $db->beginTransaction();
            try {
                $db->createCommand()->delete('threat_article_actor', ['article_id' => $ids])->execute();
                if ($links) {
                    $db->createCommand()->batchInsert('threat_article_actor', ['article_id', 'group_id'], $links)->execute();
                }
                $db->createCommand()->update('threat_article', ['actors_checked' => 1], ['id' => $ids])->execute();
                $tx->commit();
            } catch (\Throwable $e) {
                $tx->rollBack();
                throw $e;
            }
            $tagged += count($links);
        }
        return $tagged;
    }

    /** Reads everything again, after the names or the rules changed */
    public static function retag(): int
    {
        Yii::$app->db->createCommand()->update('threat_article', ['actors_checked' => 0])->execute();
        return self::run();
    }

    private static function unread(): array
    {
        return (new Query())->select(['a.id', 'a.title', 'a.excerpt', 's.is_roundup'])->from(['a' => 'threat_article'])
            ->innerJoin(['s' => 'threat_story'], 's.id = a.story_id')
            ->where(['a.actors_checked' => 0])->orderBy(['a.id' => SORT_ASC])->limit(self::BATCH)->all();
    }

    /** Longest first, so "Lazarus Group" wins over "Lazarus" @return string[] */
    private function alternatives(array $names): array
    {
        usort($names, static fn(string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        return array_map(
            static fn(array $chunk) => implode('|', array_map(static fn(string $name) => preg_quote($name, '/'), $chunk)),
            array_chunk($names, self::NAMES_PER_PATTERN),
        );
    }
}
