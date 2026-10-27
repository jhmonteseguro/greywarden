<?php

namespace app\modules\intel\components;

/**
 * Parallel HTTP client (curl_multi) for intelligence lookups.
 *
 * Request:  ['url' => ..., 'method' => 'GET', 'headers' => ['Name: value'], 'body' => null, 'timeout' => 8]
 * Response: ['status' => int (0 = transport error), 'body' => string, 'error' => ?string, 'time' => float seconds]
 *
 * Tests replace the transport with Http::$fake = fn(array $request): array => [...].
 */
class Http
{
    public const USER_AGENT = 'Greywarden-ThreatIntel/1.0 (+internal security tool)';
    public const MAX_BODY = 8 * 1024 * 1024;

    /** @var callable|null fn(array $request): array  test double */
    public static $fake = null;

    /**
     * @param array<string, array> $requests name => request
     * @return array<string, array> name => response
     */
    public static function fetchAll(array $requests, int $defaultTimeout = 8): array
    {
        if ($requests === []) {
            return [];
        }
        if (self::$fake !== null) {
            return array_map(static fn(array $r) => (self::$fake)($r) + ['status' => 200, 'body' => '', 'error' => null, 'time' => 0.0], $requests);
        }

        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $name => $request) {
            $handle = curl_init($request['url']);
            $headers = array_merge(['Accept: application/json, text/plain;q=0.9, */*;q=0.5'], $request['headers'] ?? []);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => min(5, $request['timeout'] ?? $defaultTimeout),
                CURLOPT_TIMEOUT => $request['timeout'] ?? $defaultTimeout,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_NOPROGRESS => false,
                // Abort oversized bodies
                CURLOPT_PROGRESSFUNCTION => static fn($h, $dlTotal, $dlNow) => $dlNow > self::MAX_BODY ? 1 : 0,
            ]);
            $method = strtoupper($request['method'] ?? 'GET');
            if ($method !== 'GET') {
                curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($handle, CURLOPT_POSTFIELDS, $request['body'] ?? '');
            }
            curl_multi_add_handle($multi, $handle);
            $handles[$name] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.25);
            }
        } while ($running && $status === CURLM_OK);

        $responses = [];
        foreach ($handles as $name => $handle) {
            $error = curl_error($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $responses[$name] = [
                'status' => $error !== '' ? 0 : $code,
                'body' => (string) curl_multi_getcontent($handle),
                'error' => $error !== '' ? $error : null,
                'time' => round((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME), 3),
            ];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
        return $responses;
    }

    /** Decoded JSON body as array, or null. */
    public static function json(?array $response): ?array
    {
        if (!$response || $response['body'] === '') {
            return null;
        }
        $data = json_decode($response['body'], true);
        return is_array($data) ? $data : null;
    }

    /** Human description of a failed response */
    public static function errorText(?array $response): string
    {
        if (!$response) {
            return 'No response';
        }
        if ($response['status'] === 0) {
            return $response['error'] ?: 'Connection failed';
        }
        return match ($response['status']) {
            401, 403 => 'Access denied (HTTP ' . $response['status'] . ') – check the API key',
            429 => 'Rate limited by the provider (HTTP 429) – try again later',
            default => 'HTTP ' . $response['status'],
        };
    }
}
