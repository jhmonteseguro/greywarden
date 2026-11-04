<?php

namespace tests\unit;

use app\modules\threat\components\FeedParser;
use app\modules\threat\models\ThreatCve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reading feeds: RSS, Atom, RDF and the KEV catalogue, the broken ones real publishers
 * serve, and everything a hostile feed might try.
 */
class ThreatFeedParserTest extends TestCase
{
    private const RSS = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel><title>Example</title>
<item>
  <title><![CDATA[Flaw in <b>Widget</b> &amp; friends]]></title>
  <link>https://news.example.com/a</link>
  <guid isPermaLink="false">item-1</guid>
  <description><![CDATA[<p>Short summary.</p>]]></description>
  <content:encoded><![CDATA[<p>Long text naming cve-2026-123456 and CVE-2026-1234.</p>]]></content:encoded>
  <pubDate>Wed, 04 Nov 2026 11:00:18 -0500</pubDate>
</item>
</channel></rss>
XML;

    public function testRss(): void
    {
        $items = FeedParser::parseXml(self::RSS);
        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertSame('item-1', $item['guid']);
        $this->assertSame('Flaw in Widget & friends', $item['title']);
        $this->assertSame('https://news.example.com/a', $item['url']);
        $this->assertSame('Short summary.', $item['excerpt']);
        // CVEs come from the full text, though only a short extract is kept
        $this->assertSame(['CVE-2026-123456', 'CVE-2026-1234'], $item['cves']);
        $this->assertSame(strtotime('2026-11-04 16:00:18 UTC'), $item['published_at']);
        $this->assertNull($item['kev']);
    }

    public function testAtomUsesTheAlternateLink(): void
    {
        $items = FeedParser::parseXml(<<<'XML'
<feed xmlns="http://www.w3.org/2005/Atom"><title>Example</title>
<entry>
  <title type="html">Atom &amp;amp; friends</title>
  <link rel="self" href="https://news.example.com/self"/>
  <link rel="alternate" href="https://news.example.com/post"/>
  <id>tag:example,2026:1</id><published>2026-10-27T00:00:00-07:00</published>
  <summary>Summary here</summary>
</entry>
</feed>
XML);
        $this->assertSame('Atom & friends', $items[0]['title']);
        $this->assertSame('https://news.example.com/post', $items[0]['url']);
        $this->assertSame('tag:example,2026:1', $items[0]['guid']);
        $this->assertSame('Summary here', $items[0]['excerpt']);
    }

    public function testRdfAndPermalinkGuid(): void
    {
        $items = FeedParser::parseXml(<<<'XML'
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<item><title>RDF item</title><link>https://news.example.com/rdf</link><dc:date>2026-11-01T10:00:00Z</dc:date></item>
</rdf:RDF>
XML);
        $this->assertSame('https://news.example.com/rdf', $items[0]['guid'], 'no guid: the link identifies it');
        $this->assertSame(strtotime('2026-11-01T10:00:00Z'), $items[0]['published_at']);

        $items = FeedParser::parseXml('<rss><channel><item><title>Only a guid</title><guid>https://news.example.com/g</guid></item></channel></rss>');
        $this->assertSame('https://news.example.com/g', $items[0]['url']);
    }

    public function testByteOrderMarkAndBrokenFeedsAreReadAsFarAsPossible(): void
    {
        $this->assertCount(1, FeedParser::parseXml("\xEF\xBB\xBF" . self::RSS));
        // A stray character in the second item, as in a real publisher's feed
        $broken = '<rss><channel><item><title>Good</title><link>https://news.example.com/1</link></item>'
            . '<item><title>Bad & broken <</title><link>https://news.example.com/2</link></item></channel></rss>';
        $titles = array_column(FeedParser::parseXml($broken), 'title');
        $this->assertContains('Good', $titles);
    }

    #[DataProvider('notFeeds')]
    public function testAnythingElseIsRefused(string $body): void
    {
        $this->expectException(RuntimeException::class);
        FeedParser::parseXml($body);
    }

    public static function notFeeds(): array
    {
        return [
            'empty' => ['   '],
            'html error page' => ['<!DOCTYPE html><html><head><title>404</title></head><body>Not found</body></html>'],
            'html without doctype' => ['<html><body>Not found</body></html>'],
            'json' => ['{"not": "xml"}'],
            'external entity' => ['<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&x;</title><link>https://a.example/1</link></item></channel></rss>'],
            'entity expansion' => ['<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]><rss><channel><item><title>&b;</title><link>https://a.example/1</link></item></channel></rss>'],
        ];
    }

    public function testItemsWithoutASafeLinkOrTitleAreDropped(): void
    {
        $items = FeedParser::parseXml('<rss><channel>'
            . '<item><title>Script link</title><link>javascript:alert(1)</link></item>'
            . '<item><title>Data link</title><link>data:text/html,hello</link></item>'
            . '<item><title>File link</title><link>file:///etc/passwd</link></item>'
            . '<item><title>   </title><link>https://news.example.com/no-title</link></item>'
            . '<item><title>Kept</title><link>http://news.example.com/kept</link></item>'
            . '</channel></rss>');
        $this->assertSame(['Kept'], array_column($items, 'title'));
        $this->assertNull(FeedParser::safeUrl('https://example.com/' . str_repeat('a', FeedParser::URL_LENGTH)));
    }

    public function testCleanLeavesPlainTextOnly(): void
    {
        $this->assertSame('Hello', FeedParser::clean('<script>steal()</script><style>p{}</style>Hello'));
        $this->assertSame('One Two', FeedParser::clean('<p>One</p><p>Two</p>'));
        // Markup hidden behind entities must not survive decoding
        $this->assertSame('alert', FeedParser::clean('&lt;img src=x onerror=x&gt;alert'));
        $this->assertSame('a b', FeedParser::clean("a\u{202E}\u{200B}\x07b"));
        $this->assertSame('caf', mb_substr(FeedParser::clean("caf\xE9"), 0, 3), 'invalid UTF-8 does not break it');
    }

    public function testExcerptDropsBoilerplateAndStaysShort(): void
    {
        $this->assertSame('Successful exploitation allows code execution.', FeedParser::excerpt('<a>View CSAF</a> <h2>Summary</h2> Successful exploitation allows code execution.'));
        $this->assertSame('The real text.', FeedParser::excerpt('The real text. The post Some title appeared first on Example News.'));
        $long = FeedParser::excerpt(str_repeat('word ', 300));
        $this->assertLessThanOrEqual(FeedParser::EXCERPT_LENGTH, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
        $this->assertStringEndsWith('…', FeedParser::excerpt(str_repeat('x', 900)), 'no spaces to cut at');
    }

    public function testDates(): void
    {
        $this->assertNull(FeedParser::date('not a date'));
        $this->assertSame(strtotime('2026-10-31 18:00:09 UTC'), FeedParser::date('Sat, 31 Oct 2026 18:00:09 Z'));
    }

    public function testCveExtraction(): void
    {
        $this->assertSame(['CVE-2026-119977', 'CVE-2000-0411'], ThreatCve::extract('See cve-2026-119977, CVE-2026-119977 and CVE-2000-0411 but not CVE-26-1 or XCVE-2026-12345.'));
        $this->assertSame([], ThreatCve::extract('CVE-2026-' . str_repeat('1', 40)), 'too long to be real, and too long for the column');
    }

    public function testKevCatalogue(): void
    {
        $items = FeedParser::parse((string) json_encode(['vulnerabilities' => [
            ['cveID' => 'CVE-2026-1111', 'vendorProject' => 'Acme', 'product' => 'Gateway', 'vulnerabilityName' => 'Acme Gateway Bypass', 'dateAdded' => '2026-11-01',
                'shortDescription' => 'A <b>bypass</b>.', 'requiredAction' => 'Patch.', 'dueDate' => '2026-11-22', 'knownRansomwareCampaignUse' => 'Known'],
            ['cveID' => 'CVE-2026-2222', 'dateAdded' => 'soon', 'knownRansomwareCampaignUse' => 'Unknown'],
            ['cveID' => 'not-a-cve'],
        ]]), 'kev');
        $this->assertCount(2, $items);
        $this->assertSame('CVE-2026-1111: Acme Gateway Bypass', $items[0]['title']);
        $this->assertSame('https://nvd.nist.gov/vuln/detail/CVE-2026-1111', $items[0]['url']);
        $this->assertSame(['CVE-2026-1111'], $items[0]['cves']);
        $this->assertSame(strtotime('2026-11-01'), $items[0]['published_at']);
        $this->assertSame(['description' => 'A bypass.', 'vendor' => 'Acme', 'product' => 'Gateway', 'kev_added_on' => '2026-11-01', 'kev_due_on' => '2026-11-22', 'kev_ransomware' => 1, 'kev_action' => 'Patch.'], $items[0]['kev']);

        $this->assertStringContainsString('added to the Known Exploited', $items[1]['title']);
        $this->assertNull($items[1]['published_at']);
        $this->assertSame(0, $items[1]['kev']['kev_ransomware']);
        $this->assertNull($items[1]['kev']['vendor']);

        $this->expectException(RuntimeException::class);
        FeedParser::parse('{"title": "something else"}', 'kev');
    }
}
