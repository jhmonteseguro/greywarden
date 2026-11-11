<?php

namespace app\components;

use app\models\User;
use app\models\UserPasskey;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Yii;
use yii\base\UserException;

/**
 * Passkeys (WebAuthn), with lbuchs/webauthn doing the cryptography.
 *
 * A passkey belongs to a site name, the relying party id: WEBAUTHN_RP_ID, or else the host name
 * Greywarden is opened on. Browsers refuse IP addresses, so on http://127.0.0.1 passkeys are not
 * offered; open Greywarden as http://localhost or by its name over HTTPS. Browsers also need
 * HTTPS for any name other than localhost.
 *
 * Passkeys are created as discoverable credentials, so they also sign in without a username or
 * password. That sign-in requires user verification (PIN or biometrics on the device), which
 * makes it two factors on its own. As a second step after the password, presence is enough.
 */
final class Passkeys
{
    /** Seconds a challenge stays valid */
    public const CHALLENGE_TTL = 300;
    /** Seconds the browser waits for the authenticator */
    public const TIMEOUT = 120;

    private const SESSION_CREATE = 'passkey.create';
    private const SESSION_GET = 'passkey.get';

    /** The relying party id for this request, or null when passkeys cannot work here */
    public static function rpId(): ?string
    {
        $host = strtolower((string) parse_url(Yii::$app->request->getHostInfo(), PHP_URL_HOST));
        $host = trim($host, '[].');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $configured = strtolower(trim((string) (Yii::$app->params['webauthn.rpId'] ?? '')));
        if ($configured === '') {
            return $host;
        }
        // The id must be the host itself or a domain it belongs to
        return $host === $configured || str_ends_with($host, '.' . $configured) ? $configured : null;
    }

    /** Why passkeys are not offered on this address, or null when they are */
    public static function unavailableReason(): ?string
    {
        if (self::rpId() !== null) {
            if (!Yii::$app->request->getIsSecureConnection() && self::rpId() !== 'localhost') {
                return 'Passkeys need Greywarden to be opened over HTTPS (or as http://localhost).';
            }
            return null;
        }
        $host = (string) parse_url(Yii::$app->request->getHostInfo(), PHP_URL_HOST);
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return 'Passkeys do not work on an IP address. Open Greywarden by name, for example http://localhost'
                . (self::port() ? ':' . self::port() : '') . ', and add your passkey there.';
        }
        return sprintf('Passkeys are set up for %s, not for this address.', Yii::$app->params['webauthn.rpId'] ?? '');
    }

    public static function available(): bool
    {
        return self::unavailableReason() === null;
    }

    /** Options for navigator.credentials.create(), kept with the challenge in the session */
    public static function registrationOptions(User $user): \stdClass
    {
        $handle = self::handle($user);
        $exclude = array_map(
            static fn(string $id) => self::fromBase64Url($id),
            UserPasskey::find()->select('credential_id')->where(['user_id' => $user->id])->column()
        );
        $server = self::server();
        $args = $server->getCreateArgs($handle, $user->username, $user->full_name, self::TIMEOUT, true, 'preferred', null, $exclude);
        Yii::$app->session->set(self::SESSION_CREATE, [
            'challenge' => self::toBase64Url($server->getChallenge()->getBinaryString()),
            'user' => $user->id, 'at' => time(),
        ]);
        return $args;
    }

    /**
     * Checks the browser's answer to registrationOptions() and saves the passkey.
     * @param array{clientDataJSON?:string, attestationObject?:string} $response base64url values
     */
    public static function register(User $user, string $name, array $response): UserPasskey
    {
        $challenge = self::takeChallenge(self::SESSION_CREATE, $user->id);
        $clientData = self::decodeField($response, 'clientDataJSON');
        self::checkOrigin($clientData);
        try {
            $data = self::server()->processCreate($clientData, self::decodeField($response, 'attestationObject'), $challenge, false, true, false);
        } catch (WebAuthnException $e) {
            throw new UserException('The passkey could not be checked: ' . $e->getMessage() . '.');
        }
        $passkey = new UserPasskey([
            'user_id' => $user->id,
            'name' => mb_substr(trim($name) !== '' ? trim($name) : 'Passkey', 0, 64),
            'credential_id' => self::toBase64Url($data->credentialId),
            'credential_hash' => hash('sha256', $data->credentialId),
            'public_key' => $data->credentialPublicKey,
            'sign_count' => (int) ($data->signatureCounter ?? 0),
            'aaguid' => self::formatAaguid($data->AAGUID ?? null),
            'backed_up' => (int) (bool) ($data->isBackedUp ?? false),
            'created_at' => Actor::now(),
        ]);
        if (UserPasskey::find()->where(['credential_hash' => $passkey->credential_hash])->exists()) {
            throw new UserException('This passkey is already registered.');
        }
        $passkey->save(false);
        return $passkey;
    }

    /**
     * Options for navigator.credentials.get(). With $user (the second step after the password)
     * only that user's passkeys are offered; without (sign-in with a passkey) the browser offers
     * any passkey it holds for this site.
     */
    public static function loginOptions(?User $user): \stdClass
    {
        $ids = $user === null ? [] : array_map(
            static fn(string $id) => self::fromBase64Url($id),
            UserPasskey::find()->select('credential_id')->where(['user_id' => $user->id])->column()
        );
        $server = self::server();
        $args = $server->getGetArgs($ids, self::TIMEOUT, true, true, true, true, true, $user === null ? 'required' : 'preferred');
        Yii::$app->session->set(self::SESSION_GET, [
            'challenge' => self::toBase64Url($server->getChallenge()->getBinaryString()),
            'user' => $user?->id, 'at' => time(),
        ]);
        return $args;
    }

    /**
     * Checks the browser's answer to loginOptions() and returns the user it signs in.
     * @param array{id?:string, clientDataJSON?:string, authenticatorData?:string, signature?:string, userHandle?:string|null} $response
     */
    public static function authenticate(?User $pending, array $response): User
    {
        $challenge = self::takeChallenge(self::SESSION_GET, $pending?->id);
        $credentialId = self::decodeField($response, 'id');
        $passkey = UserPasskey::findOne(['credential_hash' => hash('sha256', $credentialId)]);
        if ($passkey === null || ($pending !== null && (int) $passkey->user_id !== (int) $pending->id)) {
            throw new UserException('This passkey is not registered with Greywarden.');
        }
        $user = $pending ?? User::findOne($passkey->user_id);
        if ($user === null || !$user->isActive()) {
            throw new UserException('This account is disabled.');
        }
        $handle = (string) ($response['userHandle'] ?? '');
        if ($handle !== '' && (string) $user->passkey_handle !== '' && !hash_equals(self::toBase64Url(self::fromBase64Url($user->passkey_handle)), $handle)) {
            throw new UserException('This passkey belongs to another account.');
        }
        $clientData = self::decodeField($response, 'clientDataJSON');
        self::checkOrigin($clientData);
        $server = self::server();
        try {
            $server->processGet(
                $clientData,
                self::decodeField($response, 'authenticatorData'),
                self::decodeField($response, 'signature'),
                $passkey->public_key,
                $challenge,
                (int) $passkey->sign_count,
                $pending === null
            );
        } catch (WebAuthnException $e) {
            throw new UserException('The passkey could not be checked: ' . $e->getMessage() . '.');
        }
        $passkey->updateAttributes([
            'sign_count' => (int) ($server->getSignatureCounter() ?? $passkey->sign_count),
            'last_used_at' => Actor::now(),
        ]);
        return $user;
    }

    /** The random id passkeys store for the user (created on first use) */
    public static function handle(User $user): string
    {
        if ((string) $user->passkey_handle === '') {
            $user->updateAttributes(['passkey_handle' => self::toBase64Url(random_bytes(32))]);
        }
        return self::fromBase64Url($user->passkey_handle);
    }

    public static function toBase64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function fromBase64Url(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    private static function server(): WebAuthn
    {
        $rpId = self::rpId();
        if ($rpId === null || !self::available()) {
            throw new UserException((string) self::unavailableReason());
        }
        return new WebAuthn(Branding::appName(), $rpId, ['none'], true);
    }

    private static function takeChallenge(string $key, ?int $userId): ByteBuffer
    {
        $session = Yii::$app->session;
        $saved = $session->get($key);
        $session->remove($key);
        if (!is_array($saved) || time() - (int) $saved['at'] > self::CHALLENGE_TTL || ($saved['user'] ?? null) !== $userId) {
            throw new UserException('The passkey request has expired. Try again.');
        }
        return new ByteBuffer(self::fromBase64Url((string) $saved['challenge']));
    }

    /**
     * The browser reports the page the request came from. It must be this exact origin (scheme,
     * host and port): stricter than the library, which only matches the end of the host name.
     */
    private static function checkOrigin(string $clientDataJson): void
    {
        $origin = json_decode($clientDataJson, true)['origin'] ?? null;
        if (!is_string($origin) || rtrim($origin, '/') !== rtrim(Yii::$app->request->getHostInfo(), '/')) {
            throw new UserException('The passkey request came from another site.');
        }
    }

    private static function decodeField(array $response, string $field): string
    {
        $value = $response[$field] ?? null;
        $binary = is_string($value) ? self::fromBase64Url($value) : '';
        if ($binary === '') {
            throw new UserException('The browser sent an incomplete passkey response.');
        }
        return $binary;
    }

    /** "xxxxxxxx-xxxx-..." (the authenticator model), or null when there is none */
    public static function formatAaguid(mixed $aaguid): ?string
    {
        $binary = $aaguid instanceof ByteBuffer ? $aaguid->getBinaryString() : (is_string($aaguid) ? $aaguid : '');
        if (strlen($binary) !== 16) {
            return null;
        }
        $hex = bin2hex($binary);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private static function port(): ?int
    {
        $port = parse_url(Yii::$app->request->getHostInfo(), PHP_URL_PORT);
        return is_int($port) ? $port : null;
    }
}
