<?php

namespace tests\support;

/**
 * A software passkey for tests: an EC P-256 key pair that answers WebAuthn create and get
 * requests the way a browser and authenticator would (attestation "none"), so components/Passkeys
 * and lbuchs/webauthn are exercised with real signatures.
 */
final class SoftAuthenticator
{
    public string $credentialId;
    public int $counter = 0;
    /** User handle stored at registration (binary) */
    public string $userHandle = '';
    private \OpenSSLAsymmetricKey $key;

    public function __construct(public string $origin = 'http://localhost', public string $rpId = 'localhost', public bool $userVerified = true)
    {
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->credentialId = random_bytes(32);
    }

    /**
     * The browser's answer to navigator.credentials.create() with these options (decoded JSON).
     * @return array{clientDataJSON:string, attestationObject:string} base64url
     */
    public function create(array $options, array $clientDataOverrides = []): array
    {
        $pk = $options['publicKey'];
        $this->userHandle = self::b64d($pk['user']['id']);
        $details = openssl_pkey_get_details($this->key)['ec'];
        $cose = self::map([1 => 2, 3 => -7, -1 => 1, -2 => ['bytes' => $details['x']], -3 => ['bytes' => $details['y']]]);
        $authData = hash('sha256', $this->rpId, true) . chr(0x01 | ($this->userVerified ? 0x04 : 0) | 0x40) . pack('N', $this->counter)
            . str_repeat("\0", 16) . pack('n', strlen($this->credentialId)) . $this->credentialId . $cose;
        $attestation = self::map(['fmt' => 'none', 'attStmt' => self::map([]), 'authData' => ['bytes' => $authData]], true);
        $clientData = json_encode($clientDataOverrides + [
            'type' => 'webauthn.create', 'challenge' => $pk['challenge'], 'origin' => $this->origin, 'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
        return ['clientDataJSON' => self::b64($clientData), 'attestationObject' => self::b64($attestation)];
    }

    /**
     * The browser's answer to navigator.credentials.get().
     * @return array{id:string, clientDataJSON:string, authenticatorData:string, signature:string, userHandle:string} base64url
     */
    public function get(array $options, array $clientDataOverrides = [], ?int $counter = null): array
    {
        $this->counter = $counter ?? $this->counter + 1;
        $authData = hash('sha256', $this->rpId, true) . chr(0x01 | ($this->userVerified ? 0x04 : 0)) . pack('N', $this->counter);
        $clientData = json_encode($clientDataOverrides + [
            'type' => 'webauthn.get', 'challenge' => $options['publicKey']['challenge'], 'origin' => $this->origin, 'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);
        return [
            'id' => self::b64($this->credentialId),
            'clientDataJSON' => self::b64($clientData),
            'authenticatorData' => self::b64($authData),
            'signature' => self::b64($signature),
            'userHandle' => self::b64($this->userHandle),
        ];
    }

    public static function b64(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function b64d(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'));
    }

    // ---- minimal CBOR (RFC 8949) for the shapes WebAuthn uses --------------------------

    /** A map; values that are ['bytes' => ...] become byte strings, strings text, ints ints */
    private static function map(array $entries, bool $textKeys = false): string
    {
        $out = self::head(5, count($entries));
        foreach ($entries as $key => $value) {
            $out .= is_int($key) && !$textKeys ? self::int($key) : self::head(3, strlen((string) $key)) . $key;
            if (is_array($value) && isset($value['bytes'])) {
                $out .= self::head(2, strlen($value['bytes'])) . $value['bytes'];
            } elseif (is_int($value)) {
                $out .= self::int($value);
            } elseif (is_string($value) && $value !== '' && (ord($value[0]) >> 5) === 5) {
                $out .= $value; // an already-encoded map
            } else {
                $out .= self::head(3, strlen((string) $value)) . $value;
            }
        }
        return $out;
    }

    private static function int(int $value): string
    {
        return $value >= 0 ? self::head(0, $value) : self::head(1, -1 - $value);
    }

    private static function head(int $major, int $length): string
    {
        $type = $major << 5;
        return match (true) {
            $length < 24 => chr($type | $length),
            $length < 256 => chr($type | 24) . chr($length),
            $length < 65536 => chr($type | 25) . pack('n', $length),
            default => chr($type | 26) . pack('N', $length),
        };
    }
}
