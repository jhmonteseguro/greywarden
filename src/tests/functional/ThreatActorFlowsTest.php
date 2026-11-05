<?php

namespace tests\functional;

use app\models\Lookup;
use app\models\Organisation;
use app\modules\attack\models\AttackGroup;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use app\modules\threat\models\ThreatArticle;
use app\modules\threat\models\ThreatCve;
use app\modules\threat\models\ThreatStory;
use app\modules\threat\models\ThreatTriage;
use tests\FunctionalTestCase;
use Yii;
use yii\db\Query;

/**
 * Threat actor pages, and raising a risk or an incident from a story, on the demo data.
 */
class ThreatActorFlowsTest extends FunctionalTestCase
{
    protected static bool $seed = true;

    protected function tearDown(): void
    {
        Organisation::switchTo(null);
        parent::tearDown();
    }

    private function triage(string $title): ThreatTriage
    {
        return ThreatTriage::find()->forOrganisation()->joinWith('story')->andWhere(['like', 'threat_story.title', $title])->one();
    }

    private function lookup(string $type): int
    {
        return (int) Lookup::find()->select('id')->where(['type' => $type])->scalar();
    }

    private function riskPost(string $title, array $extra = []): array
    {
        return ['Risk' => array_merge([
            'title' => $title, 'category_id' => $this->lookup(Lookup::TYPE_RISK_CATEGORY), 'owner_id' => $this->userIdFor('analyst'), 'status' => 'identified',
            'inherent_likelihood' => 3, 'inherent_impact' => 4, 'date_identified' => date('Y-m-d'),
        ], $extra)];
    }

    private function incidentPost(string $title): array
    {
        return ['Incident' => [
            'title' => $title, 'category_id' => $this->lookup(Lookup::TYPE_INCIDENT_CATEGORY), 'severity' => 'high', 'status' => 'new', 'detected_at' => date('Y-m-d\TH:i'),
        ]];
    }

    // ---- Actor pages -----------------------------------------------------------------------

    public function testActorListPutsTheOneInTheNewsFirst(): void
    {
        $this->loginAs('viewer');
        $this->get('/threat/actors');
        $this->assertOk();
        $this->assertSee('Threat Actors');
        $this->assertSee('In the news');
        $this->assertSee('Mitigation gaps');
        $this->assertDoesNotMatchRegularExpression('/G\d{4}\s*·\s*·/', $this->body);
        $this->assertSee('The MITRE Corporation');
        $this->assertStringContainsString('Wizard Spider', substr($this->body, 0, (int) strpos($this->body, '<tr', (int) strpos($this->body, 'Wizard Spider'))));
        $this->assertLessThan(strpos($this->body, 'Aquatic Panda') ?: PHP_INT_MAX, strpos($this->body, 'Wizard Spider'));

        $this->get('/threat/actors?filter=named');
        $this->assertSee('Wizard Spider');
        $this->assertNotSee('APT29');

        $this->get('/threat/actors?q=' . urlencode('Cozy Bear'));
        $this->assertSee('APT29');
        $this->assertNotSee('Wizard Spider');

        $this->get('/threat/actors?q=nothing-matches');
        $this->assertSee('No threat actors match');
        $this->get('/threat/actors?filter=named&q=nothing-matches');
        $this->assertSee('No threat actor is named in your news yet');

        foreach (['name', '-name', 'technique_count', '-last_named_at'] as $sort) {
            $this->get('/threat/actors?sort=' . $sort);
            $this->assertOk();
        }
    }

    public function testActorPage(): void
    {
        $this->loginAs('viewer');
        $this->get('/threat/actors/G0102');
        $this->assertOk();
        $this->assertSee('Wizard Spider');
        $this->assertSee('https://attack.mitre.org/groups/G0102/');
        $this->assertSee('Also known as');
        $this->assertSee('Regional port operator');
        $this->assertSee('Initial Access');
        $this->assertSee('gw-threat-tactic');
        $this->assertMatchesRegularExpression('/\d+ exposed/', $this->body);
        // The actionable list comes first, and long technique lists are cut short with the rest in a tooltip
        $this->assertLessThan(strpos($this->body, 'Techniques by tactic'), strpos($this->body, 'Mitigations against this actor'));
        $this->assertMatchesRegularExpression('/<span class="gw-mono" title="[^"]+">[^<]+ \+\d+<\/span>/', $this->body);
        $this->assertSee('Mitigations against this actor');
        $this->assertSee('A gap means no control');
        $this->assertSee('gw-coverage__row');
        $this->assertSee('The MITRE Corporation');

        $this->get('/threat/actors/g0102');
        $this->assertOk();
        $this->get('/threat/actors/G9999');
        $this->assertStatus(404);
    }

    public function testAnActorWithNoNewsOrTechniques(): void
    {
        $this->loginAs('viewer');
        $this->get('/threat/actors/G0016');
        $this->assertSee('No story names this group yet');

        $group = new AttackGroup(['external_id' => 'G9901', 'name' => 'Empty Group']);
        $group->save(false);
        $this->get('/threat/actors/G9901');
        $this->assertOk();
        $this->assertSee('MITRE lists no techniques for this group');
        $this->assertNotSee('Also known as');
    }

    public function testAMergedGroupPointsToItsReplacement(): void
    {
        $this->loginAs('viewer');
        $old = AttackGroup::findOne(['name' => 'Charming Kitten']);
        $this->assertNotNull($old->revoked_by);
        $this->get('/threat/actors/' . $old->external_id);
        $this->assertOk();
        $this->assertSee('MITRE has merged this group into');
        $this->assertSee('Deprecated');
    }

    public function testStoryPageNamesItsActors(): void
    {
        $this->loginAs('viewer');
        $this->get('/threat/' . $this->triage('Regional port operator')->id);
        $this->assertSee('Threat actors named');
        $this->assertSee('/threat/actors/G0102');

        $this->get('/threat/' . $this->triage('Quillon')->id);
        $this->assertNotSee('Threat actors named');
    }

    public function testActorsAreSearchable(): void
    {
        $this->loginAs('viewer');
        $this->get('/search?q=G0102');
        $this->assertRedirect('/threat/actors/G0102');
        $this->get('/search?q=' . urlencode('Cozy Bear'));
        $this->assertSee('Threat actors');
        $this->assertSee('APT29');
        $this->get('/dashboard');
        $this->assertSee('/threat/actors');
    }

    public function testGroupTextIsNeverRenderedAsMarkup(): void
    {
        $this->loginAs('viewer');
        AttackGroup::updateAll(['name' => '<img src=x onerror=alert(1)>', 'aliases' => json_encode(['<script>alert(2)</script>']), 'description' => '<script>alert(3)</script>'], ['external_id' => 'G0102']);
        foreach (['/threat/actors?filter=named', '/threat/actors/G0102'] as $url) {
            $this->get($url);
            $this->assertOk();
            $this->assertNotSee('<img src=x');
            $this->assertNotSee('<script>alert(');
        }
    }

    public function testOtherOrganisationsSeeNoneOfThisNews(): void
    {
        $this->loginAs('viewer');
        $now = date('Y-m-d H:i:s');
        Yii::$app->db->createCommand()->insert('organisation', ['name' => 'Second', 'created_at' => $now, 'updated_at' => $now])->execute();
        Organisation::switchTo((int) Yii::$app->db->getLastInsertID());

        $this->get('/threat/actors?filter=named');
        $this->assertNotSee('Wizard Spider');
        $this->get('/threat/actors/G0102');
        $this->assertOk();
        $this->assertSee('No story names this group yet');
        $this->assertNotSee('Regional port operator');
    }

    // ---- Raising records --------------------------------------------------------------------

    public function testRaiseButtonsAreForThoseWhoCanRaise(): void
    {
        $id = $this->triage('Quillon')->id;
        $this->loginAs('viewer');
        $this->get("/threat/$id");
        $this->assertNotSee('Raise risk');
        $this->get("/risks/create?threat=$id");
        $this->assertStatus(403);
        $this->post("/incidents/create?threat=$id", $this->incidentPost('Nope'));
        $this->assertStatus(403);

        $this->loginAs('analyst');
        $this->get("/threat/$id");
        $this->assertSee('Raise risk');
        $this->assertSee('Raise incident');
        $this->assertSee("/risks/create?threat=$id");
        $this->assertSee("/incidents/create?threat=$id");
    }

    public function testTheRiskFormOpensFilledInFromTheStory(): void
    {
        $this->loginAs('analyst');
        $triage = $this->triage('Northgate VPN zero-day');
        $this->get("/risks/create?threat={$triage->id}");
        $this->assertOk();
        $this->assertSee('Filled in from');
        $this->assertSee('Choose a category and score the likelihood and impact.');
        $this->assertSee('Northgate VPN zero-day exploited in the wild');
        $this->assertSee('Coverage:');
        $this->assertSee('https://news.example.com/northgate-zero-day');
        $this->assertSee('Raised from Threat Watch ' . $triage->getReferenceValue());
        $this->assertSee('CVE-2026-90001 (Northgate VPN, CVSS 9.8, EPSS 94.3%, in CISA KEV)');
        $this->assertSee('value="Northgate VPN"');
    }

    public function testRaisingARiskLinksItAndMarksTheStoryRelevant(): void
    {
        $this->loginAs('analyst');
        $triage = $this->triage('Quillon');
        $this->assertSame('new', $triage->status);

        $this->post("/risks/create?threat={$triage->id}", $this->riskPost('Raised from Quillon'));
        $this->assertRedirect('/risks/');
        $risk = Risk::findOne(['title' => 'Raised from Quillon']);
        $this->assertNotNull($risk);
        $triage->refresh();
        $this->assertSame('relevant', $triage->status);
        $this->assertSame($this->userIdFor('analyst'), (int) $triage->resolved_by);
        $this->assertSame([$risk->id], array_map(static fn($r) => $r->id, $triage->risks));

        $this->get('/threat/' . $triage->id);
        $this->assertSee('Raised from this story');
        $this->assertSee($risk->reference);
        $this->get($this->url($risk));
        $this->assertSee('Raised from');
        $this->assertSee($triage->getReferenceValue());
    }

    public function testRaisingAnIncidentLinksItAndCarriesTheSeverity(): void
    {
        $this->loginAs('analyst');
        $triage = $this->triage('Northgate VPN zero-day');
        $this->get("/incidents/create?threat={$triage->id}");
        $this->assertOk();
        $this->assertSee('Check the severity and choose a category.');
        $this->assertSee('Threat Watch ' . $triage->getReferenceValue());
        $this->assertMatchesRegularExpression('/<option value="' . $triage->priority . '" selected/', $this->body);

        $this->post("/incidents/create?threat={$triage->id}", $this->incidentPost('Raised from Northgate'));
        $this->assertRedirect('/incidents/');
        $incident = Incident::findOne(['title' => 'Raised from Northgate']);
        $triage->refresh();
        $this->assertSame('relevant', $triage->status);
        $this->assertSame([$incident->id], array_map(static fn($i) => $i->id, $triage->incidents));
        $this->get('/threat/' . $triage->id);
        $this->assertSee($incident->reference);
        $this->get($this->url($incident));
        $this->assertSee('Raised from');
    }

    private function url(Risk|Incident $record): string
    {
        return ($record instanceof Risk ? '/risks/' : '/incidents/') . $record->id;
    }

    public function testADismissedStoryComesBackWhenRaised(): void
    {
        $this->loginAs('analyst');
        $triage = $this->triage('Phishing volumes');
        $this->assertSame('dismissed', $triage->status);
        $this->post("/risks/create?threat={$triage->id}", $this->riskPost('Phishing risk'));
        $triage->refresh();
        $this->assertSame('relevant', $triage->status);
        $this->assertSame('Background reading, nothing to do.', $triage->note, 'the note stays');
    }

    public function testRaisingFromARelevantStoryKeepsItsDecision(): void
    {
        $this->loginAs('analyst');
        $triage = $this->triage('Larkspur Mail');
        $decidedAt = $triage->resolved_at;
        $this->post("/risks/create?threat={$triage->id}", $this->riskPost('Larkspur risk'));
        $triage->refresh();
        $this->assertSame($decidedAt, $triage->resolved_at);
        $this->assertCount(1, $triage->risks);

        // A story can raise more than one record
        $this->post("/risks/create?threat={$triage->id}", $this->riskPost('Larkspur risk two'));
        $this->post("/incidents/create?threat={$triage->id}", $this->incidentPost('Larkspur incident'));
        $triage = ThreatTriage::findOne($triage->id);
        $this->assertCount(2, $triage->risks);
        $this->assertCount(1, $triage->incidents);
    }

    public function testNothingIsLinkedUntilTheFormIsValid(): void
    {
        $this->loginAs('analyst');
        $triage = $this->triage('Quillon');
        $this->post("/risks/create?threat={$triage->id}", $this->riskPost(''));
        $this->assertOk();
        $this->assertSee('Filled in from');
        $triage->refresh();
        $this->assertSame('new', $triage->status);
        $this->assertSame([], $triage->risks);

        // Opening the form changes nothing either
        $this->get("/incidents/create?threat={$triage->id}");
        $triage->refresh();
        $this->assertSame('new', $triage->status);
    }

    public function testAnIncidentFormOnItsOwnIsUnchanged(): void
    {
        $this->loginAs('analyst');
        $this->get('/incidents/create');
        $this->assertOk();
        $this->assertNotSee('Filled in from');
        $this->post('/incidents/create', $this->incidentPost('Plain incident'));
        $this->assertRedirect('/incidents/');
        $this->assertSame('0', (string) (new Query())->from('threat_triage_incident')->count());
    }

    public function testEditingARecordNeverLinksIt(): void
    {
        $this->loginAs('analyst');
        $triage = $this->triage('Quillon');
        $risk = Risk::find()->one();
        $this->post("/risks/{$risk->id}/update?threat={$triage->id}", ['Risk' => ['title' => 'Edited title']]);
        $triage->refresh();
        $this->assertSame([], $triage->risks);
        $this->assertSame('new', $triage->status);
    }

    public function testAStoryCannotBeRaisedFromOutsideTheOrganisation(): void
    {
        $this->loginAs('analyst');
        $triage = $this->triage('Quillon');
        $now = date('Y-m-d H:i:s');
        Yii::$app->db->createCommand()->insert('organisation', ['name' => 'Second', 'created_at' => $now, 'updated_at' => $now])->execute();
        Organisation::switchTo((int) Yii::$app->db->getLastInsertID());

        $this->get("/risks/create?threat={$triage->id}");
        $this->assertStatus(404);
        $this->post("/incidents/create?threat={$triage->id}", $this->incidentPost('Across organisations'));
        $this->assertStatus(404);
        $this->assertNull(Incident::findOne(['title' => 'Across organisations']));
    }

    public function testRaisingFromAStoryThatIsNotThere(): void
    {
        $this->loginAs('analyst');
        $this->get('/risks/create?threat=999999');
        $this->assertStatus(404);
        $this->get('/risks/create?threat[]=1');
        $this->assertStatus(404);
    }

    public function testDeletingARaisedRecordLeavesTheStory(): void
    {
        $this->loginAs('admin');
        $triage = $this->triage('Quillon');
        $this->post("/risks/create?threat={$triage->id}", ['Risk' => $this->riskPost('Short lived')['Risk'] + ['owner_id' => $this->userIdFor('admin')]]);
        $risk = Risk::findOne(['title' => 'Short lived']);
        $risk->archive();
        $this->post("/risks/{$risk->id}/delete");
        $this->assertNull(Risk::findOne($risk->id));
        $triage = ThreatTriage::findOne($triage->id);
        $this->assertSame([], $triage->risks);
        $this->get('/threat/' . $triage->id);
        $this->assertOk();
        $this->assertNotSee('Raised from this story');
    }

    public function testFeedTextStaysTextOnceRaised(): void
    {
        $this->loginAs('analyst');
        $triage = $this->triage('Quillon');
        ThreatStory::updateAll(['title' => '<img src=x onerror=alert(1)>', 'summary' => '<script>alert(2)</script>'], ['id' => $triage->story_id]);
        ThreatArticle::updateAll(['title' => '<b onclick=x>article</b>'], ['story_id' => $triage->story_id]);
        $this->get("/risks/create?threat={$triage->id}");
        $this->assertOk();
        $this->assertNotSee('<img src=x');
        $this->assertNotSee('<script>alert(2)');

        $this->post("/risks/create?threat={$triage->id}", $this->riskPost('<img src=x onerror=alert(1)>', ['description' => '<script>alert(2)</script>']));
        $risk = Risk::find()->orderBy(['id' => SORT_DESC])->one();
        $this->get($this->url($risk));
        $this->assertOk();
        $this->assertNotSee('<img src=x');
        $this->assertNotSee('<script>alert(2)');
    }

    public function testALongStoryFitsTheRecord(): void
    {
        $this->loginAs('analyst');
        $triage = $this->triage('Quillon');
        ThreatStory::updateAll(['title' => str_repeat('a', 300)], ['id' => $triage->story_id]);
        ThreatCve::ensure(['CVE-2026-99999']);
        Yii::$app->db->createCommand()->insert('threat_story_cve', ['story_id' => $triage->story_id, 'cve_id' => 'CVE-2026-99999'])->execute();
        $this->get("/risks/create?threat={$triage->id}");
        $this->assertOk();
        $this->assertSee('value="' . str_repeat('a', 255) . '"');
        $this->assertSee('CVE-2026-99999');
        $this->assertNotSee('CVE-2026-99999 (');
    }
}
