<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Http;
use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * RDAP (the structured successor of WHOIS) via the rdap.org bootstrap
 * redirector, which forwards to the authoritative RIR (ARIN, RIPE NCC, APNIC, LACNIC, AFRINIC).
 */
class RdapSource extends Source
{
    public function key(): string { return 'rdap'; }
    public function label(): string { return 'RDAP registration'; }
    public function category(): string { return self::CAT_REGISTRY; }
    public function description(): string { return 'Registered network / AS holder, allocation, contacts and abuse mailbox from the responsible RIR.'; }
    public function homepage(): string { return 'https://about.rdap.org/'; }
    public function supports(Indicator $indicator): bool { return true; }
    public function ttl(): int { return 24 * 3600; }

    public function requests(Indicator $indicator, array $facts): array
    {
        $path = $indicator->isIp() ? 'ip/' . $indicator->value : 'autnum/' . $indicator->asn;
        return ['rdap' => ['url' => 'https://rdap.org/' . $path, 'headers' => ['Accept: application/rdap+json, application/json']]];
    }

    public function collect(Indicator $indicator, array $responses, array $facts): SourceResult
    {
        $response = $responses['rdap'] ?? null;
        $data = Http::json($response);
        if (($response['status'] ?? 0) === 404) {
            return SourceResult::info('No registration record');
        }
        if (!$data || ($response['status'] ?? 0) >= 400) {
            return SourceResult::error(Http::errorText($response));
        }

        $entities = self::flattenEntities($data['entities'] ?? []);
        $registrant = self::firstByRole($entities, ['registrant', 'administrative']);
        $abuse = self::firstByRole($entities, ['abuse']);
        $events = [];
        foreach ($data['events'] ?? [] as $e) {
            if (!empty($e['eventAction']) && !empty($e['eventDate'])) {
                $events[$e['eventAction']] = substr($e['eventDate'], 0, 10);
            }
        }
        $remarks = [];
        foreach ($data['remarks'] ?? [] as $r) {
            $text = trim(implode(' ', (array) ($r['description'] ?? [])));
            if ($text !== '' && !preg_match('/terms of (use|service)|copyright|whois/i', $text)) {
                $remarks[] = mb_substr($text, 0, 300);
            }
        }

        $result = SourceResult::info(trim(($data['name'] ?? '') . ' · ' . ($registrant['name'] ?? ''), ' ·') ?: 'Registered');

        if ($indicator->isIp()) {
            $cidrs = array_map(static fn($c) => ($c['v4prefix'] ?? $c['v6prefix'] ?? '') . '/' . ($c['length'] ?? ''), $data['cidr0_cidrs'] ?? []);
            $result->row('Network name', $data['name'] ?? null)
                ->row('Handle', $data['handle'] ?? null)
                ->row('Range', isset($data['startAddress']) ? $data['startAddress'] . ' – ' . ($data['endAddress'] ?? '') : null)
                ->row('CIDR', $cidrs ? implode(', ', $cidrs) : null)
                ->row('Type', $data['type'] ?? null)
                ->row('Parent', $data['parentHandle'] ?? null);
            $result->fact('network', $cidrs[0] ?? null);
        } else {
            $result->row('AS name', $data['name'] ?? null)
                ->row('Handle', $data['handle'] ?? null)
                ->row('Range', isset($data['startAutnum']) && ($data['startAutnum'] !== ($data['endAutnum'] ?? null)) ? 'AS' . $data['startAutnum'] . ' – AS' . $data['endAutnum'] : null)
                ->row('Type', $data['type'] ?? null);
            $result->fact('as_name', $data['name'] ?? null);
        }
        $result->row('Holder', $registrant['name'] ?? null)
            ->row('Holder address', $registrant['address'] ?? null)
            ->row('Country', $data['country'] ?? $registrant['country'] ?? null)
            ->row('Abuse contact', $abuse['email'] ?? null)
            ->row('Abuse phone', $abuse['phone'] ?? null)
            ->row('Status', isset($data['status']) ? implode(', ', (array) $data['status']) : null)
            ->row('Registered', $events['registration'] ?? null)
            ->row('Last changed', $events['last changed'] ?? null)
            ->row('Source', self::sourceServer($data, $response))
            ->row('Remarks', array_slice(array_unique($remarks), 0, 4));

        $result->fact('org', $registrant['name'] ?? null)
            ->fact('abuse_email', $abuse['email'] ?? null)
            ->fact('registered_country', $data['country'] ?? null)
            ->fact('registered_at', $events['registration'] ?? null);

        $contacts = [];
        foreach ($entities as $e) {
            $contacts[] = [implode(', ', $e['roles']), $e['name'] ?? $e['handle'] ?? '—', $e['email'] ?? '', $e['handle'] ?? ''];
        }
        $result->table('Contacts', ['Role', 'Name', 'Email', 'Handle'], array_slice($contacts, 0, 12));
        return $result->rawJson($data);
    }

    /** Entities can nest (registrant -> abuse); flatten with parsed vCards. */
    public static function flattenEntities(array $entities, int $depth = 0): array
    {
        $out = [];
        foreach ($entities as $e) {
            $card = self::vcard($e['vcardArray'] ?? []);
            $out[] = ['roles' => $e['roles'] ?? [], 'handle' => $e['handle'] ?? null] + $card;
            if ($depth < 3 && !empty($e['entities'])) {
                $out = array_merge($out, self::flattenEntities($e['entities'], $depth + 1));
            }
        }
        return $out;
    }

    /** Pull fn / org / email / tel / adr / country out of a jCard */
    public static function vcard(array $vcard): array
    {
        $card = [];
        foreach ($vcard[1] ?? [] as $prop) {
            [$name, $params, , $value] = array_pad($prop, 4, null);
            switch ($name) {
                case 'fn':
                    $card['name'] ??= is_string($value) ? trim($value) : null;
                    break;
                case 'org':
                    $card['org'] ??= is_array($value) ? implode(' ', $value) : $value;
                    break;
                case 'email':
                    $card['email'] ??= is_string($value) ? strtolower($value) : null;
                    break;
                case 'tel':
                    $card['phone'] ??= is_string($value) ? preg_replace('/^tel:/', '', $value) : null;
                    break;
                case 'adr':
                    $label = $params['label'] ?? null;
                    if (is_string($label)) {
                        $card['address'] = preg_replace('/\s*\n\s*/', ', ', trim($label));
                    } elseif (is_array($value)) {
                        $card['address'] = implode(', ', array_filter(array_map(static fn($v) => is_array($v) ? implode(' ', $v) : $v, $value)));
                        $card['country'] ??= is_string(end($value)) && strlen((string) end($value)) === 2 ? end($value) : null;
                    }
                    break;
            }
        }
        return array_filter($card, static fn($v) => $v !== null && $v !== '');
    }

    private static function firstByRole(array $entities, array $roles): ?array
    {
        foreach ($roles as $role) {
            foreach ($entities as $e) {
                if (in_array($role, $e['roles'], true)) {
                    return $e;
                }
            }
        }
        return null;
    }

    private static function sourceServer(array $data, ?array $response): ?string
    {
        foreach ($data['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'self' && !empty($link['href'])) {
                return parse_url($link['href'], PHP_URL_HOST) ?: null;
            }
        }
        return $data['port43'] ?? null;
    }
}
