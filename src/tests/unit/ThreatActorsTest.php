<?php

namespace tests\unit;

use app\models\Organisation;
use app\modules\attack\components\Coverage;
use app\modules\attack\models\AttackGroup;
use app\modules\attack\models\AttackMitigation;
use app\modules\attack\models\AttackTechnique;
use app\modules\control\models\Control;
use app\modules\intel\components\Http;
use app\modules\threat\components\ActorProfile;
use app\modules\threat\components\ActorTagger;
use app\modules\threat\components\ThreatCatalogue;
use app\modules\threat\components\ThreatPuller;
use app\modules\threat\models\ActorSearch;
use app\modules\threat\models\ThreatArticle;
use app\modules\threat\models\ThreatSource;
use app\modules\threat\models\ThreatStory;
use PHPUnit\Framework\Attributes\DataProvider;
use tests\DbTestCase;
use Yii;
use yii\db\Query;

/**
 * Finding threat groups in the news, and what a group's techniques mean for this organisation.
 */
class ThreatActorsTest extends DbTestCase
{
    private int $next = 0;

    protected function setUp(): void
    {
        parent::setUp();
        ThreatCatalogue::sync();
    }

    protected function tearDown(): void
    {
        Http::$fake = null;
        Organisation::switchTo(null);
        parent::tearDown();
    }

    private function tagger(): ActorTagger
    {
        return new ActorTagger([
            1 => ActorTagger::namesOf('Lazarus Group', ['HIDDEN COBRA', 'Diamond Sleet']),
            2 => ActorTagger::namesOf('APT1', ['Comment Crew']),
            3 => ActorTagger::namesOf('APT10', ['BRONZE RIVERSIDE', 'LAPSUS$']),
            4 => ActorTagger::namesOf('Silence', ['Whisper Spider']),
            5 => ActorTagger::namesOf('Play', ['Balloonfly']),
            6 => ActorTagger::namesOf('Equation', []),
            7 => ActorTagger::namesOf('Snake', ['Turla']),
            8 => ActorTagger::namesOf('Sandworm Team', ['TEMP.Noble']),
            9 => ActorTagger::namesOf('Thrip', []),
            10 => ActorTagger::namesOf('Lotus Blossom', ['Thrip']),
        ]);
    }

    public static function mentions(): array
    {
        return [
            'name' => ['Lazarus Group steals crypto', [1]],
            'short name' => ['Lazarus hackers steal crypto', [1]],
            'alias' => ['Microsoft tracks Diamond Sleet', [1]],
            'possessive' => ["Turla's new backdoor", [7]],
            'hyphen after' => ['APT1-linked hackers return', [2]],
            'hyphen before' => ['A UAC-0099-Sandworm collaboration', [8]],
            'vendor capitals' => ['BRONZE RIVERSIDE returns', [3]],
            'vendor capitals as a name' => ['Bronze Riverside returns', [3]],
            'odd characters' => ['LAPSUS$ is back', [3]],
            'an exact id is not its neighbour' => ['APT10 and not the other', [3]],
            'a group sharing an alias' => ['Thrip targets satellites', [9, 10]],
            'several' => ['Lazarus Group and Turla both active', [1, 7]],
            'everyday word with a group word after' => ['The Silence group struck banks', [4]],
            'group word in capitals' => ['EQUATION GROUP tools leaked', []],
            'name as written with a group word' => ['Equation Group tools leaked', [6]],
            'everyday word with a group word before' => ['The threat actor Silence struck banks', [4]],
            'ransomware brand' => ['Play ransomware hits a city', [5]],
            'other ransomware is not this group' => ['Snake ransomware hits a factory', []],
            'everyday word alone' => ['Silence from the vendor as attacks grow', []],
            'everyday verb' => ['How to play it safe', []],
            'another everyday verb' => ['Play Store apps removed', []],
            'as a formula' => ['An Equation for patching', []],
            'wrong case' => ['A hidden cobra in the garden', []],
            'inside a word' => ['XLazarus Group is not a thing', []],
            'inside an id' => ['See APT100 for more', []],
            'nothing' => ['A quiet day in security', []],
        ];
    }

    #[DataProvider('mentions')]
    public function testFindingGroupsInText(string $text, array $groups): void
    {
        $found = $this->tagger()->find($text);
        sort($found);
        $this->assertSame($groups, $found);
    }

    public function testNamesOfAGroup(): void
    {
        $this->assertSame(['Lazarus Group', 'Lazarus', 'ZINC', 'BRONZE BUTLER', 'Bronze Butler'], ActorTagger::namesOf('Lazarus Group', ['ZINC', 'BRONZE BUTLER', ' ', 'X']));
    }

    public function testEveryCatalogueNameCompiles(): void
    {
        $tagger = ActorTagger::fromCatalogue();
        $this->assertSame((int) AttackGroup::findOne(['name' => 'APT29'])->id, $tagger->find('Cozy Bear phishing wave')[0]);
        $this->assertSame([], $tagger->find('The Play button stopped working in Chromium after Silence'));
        $this->assertSame([], $tagger->find('Lead engineers discuss Mercury and Platinum sponsors'));
    }

    private function article(string $title, string $excerpt = '', bool $roundup = false, ?int $story = null): ThreatArticle
    {
        $now = date('Y-m-d H:i:s');
        if ($story === null) {
            $model = new ThreatStory(['title' => $title, 'is_roundup' => (int) $roundup, 'first_seen_at' => $now, 'last_seen_at' => $now]);
            $model->save(false);
            $story = $model->id;
            Yii::$app->db->createCommand()->insert('threat_triage', ['organisation_id' => Organisation::DEFAULT_ID, 'story_id' => $story, 'priority' => 'low', 'created_at' => $now, 'updated_at' => $now])->execute();
        }
        $article = new ThreatArticle(['source_id' => ThreatSource::findOne(['key' => 'bleepingcomputer'])->id, 'story_id' => $story, 'guid_hash' => hash('sha256', 'guid' . ++$this->next),
            'title' => $title, 'url' => 'https://news.example.com/' . $this->next, 'excerpt' => $excerpt, 'published_at' => $now, 'fetched_at' => $now]);
        $article->save(false);
        return $article;
    }

    private function actorsOf(int $storyId): array
    {
        return array_map(static fn(AttackGroup $g) => $g->name, ThreatStory::findOne($storyId)->getActors());
    }

    public function testRunTagsNewArticlesOnceAndSkipsRoundups(): void
    {
        $headline = $this->article('Cozy Bear phishes diplomats');
        $excerpt = $this->article('Diplomats phished', 'Attributed to Fancy Bear by the vendor.');
        $roundup = $this->article('Weekly recap: Cozy Bear and more', '', true);
        $this->article('A second report on the same thing', 'Scattered Spider too.', false, $headline->story_id);

        $this->assertSame(3, ActorTagger::run());
        $this->assertSame(['APT29', 'Scattered Spider'], $this->actorsOf($headline->story_id));
        $this->assertSame(['APT28'], $this->actorsOf($excerpt->story_id));
        $this->assertSame([], $this->actorsOf($roundup->story_id));
        $this->assertSame('0', (string) (new Query())->from('threat_article')->where(['actors_checked' => 0])->count());

        $this->assertSame(0, ActorTagger::run(), 'an article is read once');
    }

    public function testRetagDropsWhatNoLongerMatches(): void
    {
        $article = $this->article('Cozy Bear phishes diplomats');
        ActorTagger::run();
        ThreatArticle::updateAll(['title' => 'A corrected headline'], ['id' => $article->id]);
        $this->assertSame(0, ActorTagger::retag());
        $this->assertSame([], $this->actorsOf($article->story_id));
    }

    public function testReadingAgainReplacesWhatWasRecorded(): void
    {
        $article = $this->article('Cozy Bear phishes diplomats');
        $other = AttackGroup::findOne(['name' => 'APT28']);
        Yii::$app->db->createCommand()->insert('threat_article_actor', ['article_id' => $article->id, 'group_id' => $other->id])->execute();
        $this->assertSame(1, ActorTagger::run());
        $this->assertSame(['APT29'], $this->actorsOf($article->story_id));
    }

    public function testAPullTagsWhatItRead(): void
    {
        $body = sprintf('<rss><channel><item><title>Volt Typhoon back in the grid</title><link>https://news.example.com/volt</link><pubDate>%s</pubDate></item></channel></rss>', date(DATE_RSS, time() - 3600));
        Http::$fake = static fn(array $request): array => ['status' => 200, 'body' => str_contains($request['url'], 'known_exploited') ? '{"vulnerabilities":[]}' : (str_contains($request['url'], 'bleepingcomputer') ? $body : '<rss><channel></channel></rss>')];
        $puller = new ThreatPuller();
        $puller->force = true;
        $puller->run();
        $article = ThreatArticle::findOne(['url' => 'https://news.example.com/volt']);
        $this->assertSame(['Volt Typhoon'], $this->actorsOf($article->story_id));
    }

    // ---- What a group means here ----------------------------------------------------------

    private function group(array $techniques): AttackGroup
    {
        $now = date('YmdHis');
        $group = new AttackGroup(['external_id' => 'G9' . random_int(100, 999), 'name' => "Group $now", 'aliases' => '[]']);
        $group->save(false);
        foreach ($techniques as $id) {
            Yii::$app->db->createCommand()->insert('attack_group_technique', ['group_id' => $group->id, 'technique_id' => AttackTechnique::findByExternalId($id)->id])->execute();
        }
        return $group;
    }

    /** Controls that come with the install already record some mitigations */
    private function adoptNothing(): void
    {
        Yii::$app->db->createCommand()->delete('control_attack_mitigation')->execute();
    }

    private function adopt(string $mitigation, string $status): void
    {
        $control = new Control(['title' => 'Control for ' . $mitigation, 'status' => $status, 'control_type' => 'preventive', 'nature' => 'administrative',
            'mitigationIds' => [AttackMitigation::findByExternalId($mitigation)->id]]);
        $this->assertTrue($control->save(), json_encode($control->getErrors()));
    }

    public function testProfileWithNothingAdopted(): void
    {
        $this->adoptNothing();
        $profile = new ActorProfile($this->group(['T1566.001', 'T1078']));
        $this->assertCount(2, $profile->techniques);
        $this->assertSame(0, $profile->summary['addressed']);
        $this->assertGreaterThan(0, $profile->summary['gaps']);
        $this->assertSame(Coverage::STATE_GAP, $profile->states['T1566.001']);
        $this->assertSame(0, $profile->getCoveredCount());
        $this->assertSame(0, $profile->getSeenCount());
    }

    public function testProfileTakesEachTechniquesBestMitigation(): void
    {
        $this->adoptNothing();
        $this->adopt('M1017', 'implemented');
        $profile = new ActorProfile($this->group(['T1566.001']));
        $this->assertSame(Coverage::STATE_IMPLEMENTED, $profile->states['T1566.001']);
        $this->assertSame(1, $profile->getCoveredCount());
        $this->assertSame(1, $profile->summary['addressed']);
        // The same answer as the rest of the ATT&CK pages
        $this->assertSame(Coverage::summarise(Coverage::mitigationsFor(array_map(static fn($t) => $t->id, $profile->techniques))), $profile->summary);
    }

    public function testATechniqueWithNoMitigationIsNotAGap(): void
    {
        $unmapped = (new Query())->select('t.external_id')->from(['t' => 'attack_technique'])
            ->leftJoin(['m' => 'attack_mitigation_technique'], 'm.technique_id = t.id')
            ->where(['t.is_deprecated' => 0, 't.is_subtechnique' => 0, 'm.technique_id' => null])->scalar();
        $profile = new ActorProfile($this->group([$unmapped]));
        $this->assertSame(ActorProfile::STATE_UNMAPPED, $profile->states[$unmapped]);
        $this->assertSame(0, $profile->summary['gaps']);
    }

    public function testTechniquesGroupByTacticInMatrixOrderMostExposedFirst(): void
    {
        $this->adoptNothing();
        $this->adopt('M1017', 'implemented');
        $profile = new ActorProfile($this->group(['T1566.001', 'T1190', 'T1486']));
        $byTactic = $profile->byTactic();
        $this->assertSame(['Initial Access', 'Impact'], array_map(static fn($e) => $e['tactic']->name, $byTactic));
        $this->assertSame(['T1190', 'T1566.001'], array_map(static fn($t) => $t->external_id, $byTactic[0]['techniques']));
    }

    public function testDeprecatedTechniquesAreLeftOut(): void
    {
        $group = $this->group(['T1566.001']);
        AttackTechnique::updateAll(['is_deprecated' => 1], ['external_id' => 'T1566.001']);
        $this->assertSame([], (new ActorProfile($group))->techniques);
        $this->assertSame([], ActorProfile::summaries([$group->id]));
    }

    public function testSummariesForAPageOfGroups(): void
    {
        $this->assertSame([], ActorProfile::summaries([]));
        $group = $this->group(['T1566.001']);
        $this->assertSame((new ActorProfile($group))->summary, ActorProfile::summaries([$group->id])[$group->id]);
    }

    // ---- The list ------------------------------------------------------------------------

    public function testSearchPutsGroupsInTheNewsFirst(): void
    {
        $this->article('Cozy Bear phishes diplomats');
        ActorTagger::run();

        $search = new ActorSearch();
        $first = $search->search([])->getModels()[0];
        $this->assertSame('APT29', $first->name);
        $this->assertSame('1', (string) $first->story_count);
        $this->assertNotNull($first->last_named_at);
        $this->assertGreaterThan(0, (int) $first->technique_count);

        $this->assertSame(['all' => (int) AttackGroup::find()->where(['is_deprecated' => 0])->count(), 'named' => 1], ActorSearch::counts());
        $this->assertSame(['APT29'], array_map(static fn($g) => $g->name, (new ActorSearch())->search(['filter' => 'named'])->getModels()));
    }

    public function testSearchByNameAliasOrId(): void
    {
        foreach (['APT29', 'cozy bear', 'G0016'] as $q) {
            $this->assertContains('APT29', array_map(static fn($g) => $g->name, (new ActorSearch())->search(['q' => $q])->getModels()), $q);
        }
        $this->assertSame([], (new ActorSearch())->search(['q' => 'no such group'])->getModels());
    }

    public function testBadFiltersFallBackAndRevokedGroupsAreNotListed(): void
    {
        $search = new ActorSearch();
        $this->assertGreaterThan(0, $search->search(['filter' => 'nonsense'])->getTotalCount());
        $this->assertSame('all', $search->filter);
        $this->assertNotContains('Charming Kitten', array_map(static fn($g) => $g->name, (new ActorSearch())->search(['q' => 'Charming Kitten'])->getModels()));
    }

    public function testMentionsCountOnlyThisOrganisationsStories(): void
    {
        $this->article('Cozy Bear phishes diplomats');
        ActorTagger::run();
        $now = date('Y-m-d H:i:s');
        Yii::$app->db->createCommand()->insert('organisation', ['name' => 'Second', 'created_at' => $now, 'updated_at' => $now])->execute();
        Organisation::switchTo((int) Yii::$app->db->getLastInsertID());
        $this->assertSame(0, ActorSearch::counts()['named']);
    }
}
