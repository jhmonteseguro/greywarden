<?php

namespace app\components;

use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;

/**
 * Encrypts the secrets Greywarden keeps for sign-in (authenticator app secrets) with libsodium
 * (XSalsa20-Poly1305). The key is a random file, params[mfa.keyPath] (default
 * storage/keys/mfa.key), created on first use: outside the database, so a copy of the
 * database alone does not reveal the secrets. Backups include it (scripts/backup.sh).
 */
final class SecretBox
{
    private const PREFIX = 'sb1:';

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** The plain text, or null when the value was not encrypted with this key */
    public static function decrypt(string $sealed): ?string
    {
        if (!str_starts_with($sealed, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key()
        );
        return $plain === false ? null : $plain;
    }

    public static function keyPath(): string
    {
        return (string) Yii::getAlias(Yii::$app->params['mfa.keyPath'] ?? '/var/www/storage/keys/mfa.key');
    }

    private static function key(): string
    {
        $path = self::keyPath();
        if (!is_file($path)) {
            FileHelper::createDirectory(dirname($path), 0700);
            $key = sodium_crypto_secretbox_keygen();
            // Created exclusively, so two first requests at once cannot write different keys
            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                fwrite($handle, base64_encode($key) . "\n");
                fclose($handle);
                @chmod($path, 0600);
            }
        }
        $key = base64_decode(trim((string) @file_get_contents($path)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidConfigException("The sign-in secrets key $path is missing or damaged.");
        }
        return $key;
    }
}
