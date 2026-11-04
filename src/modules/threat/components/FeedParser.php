<?php

namespace app\modules\threat\components;

use app\modules\threat\models\ThreatCve;
use app\modules\threat\models\ThreatSource;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Turns a feed body into plain items. Everything in a feed is untrusted: anyone who can get
 * words into an article controls this text. So:
 *   - documents with a DOCTYPE are refused (no real feed has one), which also rules out entity
 *     tricks, and libxml never touches the network;
 *   - markup is removed here and only plain text is stored, with invisible control and
 *     direction characters taken out, and pages still encode it when they show it;
 *   - links must be http(s), so a feed cannot plant a javascript: link.
 *
 * Malformed XML is read as far as it can be: several real feeds have stray bytes in them.
 *
 * Item: ['guid', 'title', 'url', 'excerpt', 'cves' => string[], 'published_at' => ?int, 'kev' => ?array]
 */
final class FeedParser
{
    public const EXCERPT_LENGTH = 500;
    public const TITLE_LENGTH = 500;
    public const URL_LENGTH = 2000;

    /** Boilerplate at the start or end of feed descriptions */
    private const BOILERPLATE = [
        '/^View CSAF\s+(Summary\s+)?/i',
        '/\s*The post .{0,300} appeared first on .{0,120}$/isu',
    ];

    /** @return array[] items */
    public static function parse(string $body, string $format): array
    {
        return $format === ThreatSource::FORMAT_KEV ? self::parseKev($body) : self::parseXml($body);
    }

    /**
     * RSS 2.0, RSS 1.0 (RDF) or Atom.
     * @throws RuntimeException when the body is not a feed
     */
    public static function parseXml(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', ltrim($body));
        if ($body === '') {
            throw new RuntimeException('The feed is empty.');
        }
        $internal = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->recover = true;
        $loaded = $doc->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($internal);
        if (!$loaded || $doc->doctype !== null || !in_array($doc->documentElement?->localName, ['rss', 'feed', 'RDF'], true)) {
            throw new RuntimeException('This is not an RSS or Atom feed.');
        }

        $items = [];
        foreach (['item', 'entry'] as $tag) {
            foreach ($doc->getElementsByTagNameNS('*', $tag) as $node) {
                if ($item = self::item($node)) {
                    $items[] = $item;
                }
            }
        }
        return $items;
    }

    private static function item(DOMElement $node): ?array
    {
        $fields = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $fields[$child->localName][] = $child;
            }
        }
        $text = static fn(string ...$names): string => self::firstText($fields, $names);

        $title = self::truncate(self::clean($text('title')), self::TITLE_LENGTH);
        $url = self::link($fields);
        if ($title === '' || $url === null) {
            return null;
        }
        $guid = trim($text('guid', 'id'));
        $body = implode(' ', array_map(static fn(DOMElement $e) => $e->textContent, array_merge(
            $fields['description'] ?? [], $fields['summary'] ?? [], $fields['content'] ?? [], $fields['encoded'] ?? [],
        )));
        return [
            'guid' => $guid !== '' ? $guid : $url,
            'title' => $title,
            'url' => $url,
            'excerpt' => self::excerpt($text('description', 'summary', 'content', 'encoded')),
            'cves' => ThreatCve::extract($title . ' ' . $body),
            'published_at' => self::date($text('pubDate', 'published', 'date', 'updated')),
            'kev' => null,
        ];
    }

    /** The first of the named fields with any text */
    private static function firstText(array $fields, array $names): string
    {
        foreach ($names as $name) {
            foreach ($fields[$name] ?? [] as $element) {
                if (trim($element->textContent) !== '') {
                    return $element->textContent;
                }
            }
        }
        return '';
    }

    /** RSS: the text of <link>. Atom: the href of the alternate (or only) <link>. Failing that, a permalink guid. */
    private static function link(array $fields): ?string
    {
        foreach ($fields['link'] ?? [] as $link) {
            $href = trim($link->getAttribute('href'));
            if ($href !== '' && in_array($link->getAttribute('rel'), ['', 'alternate'], true) && ($url = self::safeUrl($href))) {
                return $url;
            }
            if ($href === '' && ($url = self::safeUrl(trim($link->textContent)))) {
                return $url;
            }
        }
        foreach ($fields['guid'] ?? [] as $guid) {
            if ($guid->getAttribute('isPermaLink') !== 'false' && ($url = self::safeUrl(trim($guid->textContent)))) {
                return $url;
            }
        }
        return null;
    }

    /** The address if it is a usable web link */
    public static function safeUrl(string $url): ?string
    {
        if (strlen($url) > self::URL_LENGTH || !preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        return $url;
    }

    /** Unix time, or null when missing or unreadable */
    public static function date(string $value): ?int
    {
        $time = strtotime(trim($value));
        return $time === false ? null : $time;
    }

    /** Markup and entities out, invisible characters out, whitespace collapsed */
    public static function clean(string $html): string
    {
        $text = mb_scrub($html, 'UTF-8');
        // Script and style contents are code, not words
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#isu', ' ', $text) ?? $text;
        $text = preg_replace('/<(?:br|p|div|li|h[1-6])\b/i', ' $0', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Decoding can reveal markup that was escaped in the feed
        $text = strip_tags($text);
        $text = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function excerpt(string $html): string
    {
        $text = self::clean($html);
        foreach (self::BOILERPLATE as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }
        return self::truncate(trim($text), self::EXCERPT_LENGTH);
    }

    /** Cut at a word where there is one close to the limit */
    private static function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length - 1);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false && $space > $length * .7 ? mb_substr($cut, 0, $space) : $cut, " ,;:.-") . '…';
    }

    /**
     * The CISA KEV catalogue (JSON): every entry, with its facts under 'kev'.
     * @throws RuntimeException when the body is not the catalogue
     */
    public static function parseKev(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !is_array($data['vulnerabilities'] ?? null)) {
            throw new RuntimeException('This is not the CISA KEV catalogue.');
        }
        $items = [];
        foreach ($data['vulnerabilities'] as $row) {
            $id = strtoupper(trim((string) ($row['cveID'] ?? '')));
            if (!is_array($row) || !preg_match('/^' . trim(ThreatCve::PATTERN, '/i') . '$/', $id)) {
                continue;
            }
            $name = self::clean((string) ($row['vulnerabilityName'] ?? ''));
            $description = self::clean((string) ($row['shortDescription'] ?? ''));
            $added = self::day($row['dateAdded'] ?? null);
            $items[] = [
                'guid' => $id,
                'title' => self::truncate(sprintf('%s: %s', $id, $name !== '' ? $name : 'added to the Known Exploited Vulnerabilities catalogue'), self::TITLE_LENGTH),
                'url' => 'https://nvd.nist.gov/vuln/detail/' . $id,
                'excerpt' => self::truncate($description, self::EXCERPT_LENGTH),
                'cves' => [$id],
                'published_at' => $added !== null ? strtotime($added) : null,
                'kev' => [
                    'description' => $description !== '' ? $description : null,
                    'vendor' => mb_substr(self::clean((string) ($row['vendorProject'] ?? '')), 0, 120) ?: null,
                    'product' => mb_substr(self::clean((string) ($row['product'] ?? '')), 0, 200) ?: null,
                    'kev_added_on' => $added,
                    'kev_due_on' => self::day($row['dueDate'] ?? null),
                    'kev_ransomware' => strcasecmp((string) ($row['knownRansomwareCampaignUse'] ?? ''), 'Known') === 0 ? 1 : 0,
                    'kev_action' => self::clean((string) ($row['requiredAction'] ?? '')) ?: null,
                ],
            ];
        }
        return $items;
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
