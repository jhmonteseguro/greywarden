<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Indicator;
use app\modules\intel\components\Network;
use app\modules\intel\components\SourceResult;

/**
 * Classic WHOIS over TCP/43: IANA referral, then the authoritative RIR server.
 * The raw text is shown verbatim; a few key fields are picked out.
 */
class WhoisSource extends Source
{
    public function key(): string { return 'whois'; }
    public function label(): string { return 'WHOIS'; }
    public function category(): string { return self::CAT_REGISTRY; }
    public function description(): string { return 'Raw WHOIS record from the authoritative registry (IANA referral, port 43).'; }
    public function homepage(): string { return 'https://www.iana.org/whois'; }
    public function supports(Indicator $indicator): bool { return true; }
    public function ttl(): int { return 24 * 3600; }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $whois = Network::whois($indicator->value);
        if ($whois['text'] === null) {
            return SourceResult::error($whois['error'] ?? 'No WHOIS response');
        }
        $fields = self::fields($whois['text']);
        $name = $fields['netname'] ?? $fields['NetName'] ?? $fields['as-name'] ?? $fields['ASName'] ?? $fields['owner'] ?? null;
        $org = $fields['OrgName'] ?? $fields['org-name'] ?? $fields['descr'] ?? $fields['owner'] ?? null;

        $result = SourceResult::info(trim(($name ?? '') . ' · ' . ($whois['server'] ?? ''), ' ·'));
        $result->row('Server', implode(' → ', $whois['chain']))
            ->row('Name', $name)
            ->row('Organisation', $org)
            ->row('Range', $fields['inetnum'] ?? $fields['inet6num'] ?? $fields['NetRange'] ?? null)
            ->row('CIDR', $fields['CIDR'] ?? $fields['route'] ?? null)
            ->row('Country', $fields['country'] ?? $fields['Country'] ?? null)
            ->row('Abuse', $fields['abuse-mailbox'] ?? $fields['OrgAbuseEmail'] ?? null)
            ->row('Note', $whois['error']);
        $result->fact('abuse_email', $fields['abuse-mailbox'] ?? $fields['OrgAbuseEmail'] ?? null);
        $result->raw = $whois['text'];
        return $result;
    }

    /** First value of each "key: value" line (comments skipped) */
    public static function fields(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if ($line === '' || $line[0] === '%' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^([A-Za-z][\w-]*):\s+(.+)$/', $line, $m)) {
                $out[$m[1]] ??= trim($m[2]);
            }
        }
        return $out;
    }
}
