<?php

namespace tests\unit;

use app\components\ReferenceGenerator as G;
use app\models\Lookup;
use app\modules\decision\models\Decision;
use tests\DbTestCase;
use Yii;

class ReferenceGeneratorTest extends DbTestCase
{
    public function testFormat(): void
    {
        $this->assertSame('RSK-0001', G::format('RSK', 1));
        $this->assertSame('INC-0042', G::format('inc', 42));
        $this->assertSame('DEV-12345', G::format('DEV', 12345), 'grows beyond the padding');
        $this->assertSame('DEC-001', G::format('DEC', 1, 3));
    }

    public function testParse(): void
    {
        $this->assertSame(7, G::parse('DEV', 'DEV-0007'));
        $this->assertSame(7, G::parse('DEV', 'dev-0007'));
        $this->assertNull(G::parse('DEV', 'LAPTOP-0007'));
        $this->assertNull(G::parse('DEV', 'DEV-0007-A'));
        $this->assertNull(G::parse('DEV', null));
    }

    public function testNextNumberIgnoresCustomTagsAndNeverReusesGaps(): void
    {
        $this->assertSame(1, G::nextNumber('DEV', []));
        $this->assertSame(10, G::nextNumber('DEV', ['DEV-0001', 'DEV-0009', 'LAPTOP-JS', 'DEV-0003', null]));
        $this->assertSame(101, G::nextNumber('DEV', ['DEV-0100', 'DEV-99']));
    }

    public function testNextAgainstDatabase(): void
    {
        $db = Yii::$app->db;
        $start = G::next('decision', 'reference', 'DEC', 4, $db);
        $n = G::parse('DEC', $start);
        $this->assertNotNull($n);

        $d = new Decision([
            'title' => 'Ref test', 'decision_date' => '2026-01-01', 'decision_text' => 'x', 'decision_makers' => 'me',
            'status' => Decision::STATUS_PROPOSED, 'category_id' => $this->lookupId(Lookup::TYPE_DECISION_CATEGORY),
        ]);
        $this->assertTrue($d->save(), json_encode($d->getErrors()));
        $this->assertSame($start, $d->reference);
        $this->assertSame(G::format('DEC', $n + 1), G::next('decision', 'reference', 'DEC', 4, $db));
    }
}
