<?php

/**
 * Early bootstrap shared by web/index.php, the `yii` console script and tests.
 * Provides tiny helpers to read configuration from the environment (.env is
 * injected into the container by docker compose).
 */

if (!function_exists('gw_env')) {
    function gw_env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }

    function gw_env_bool(string $key, bool $default = false): bool
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    function gw_env_list(string $key, array $default = []): array
    {
        $value = gw_env($key);
        if ($value === null) {
            return $default;
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
    }

    /**
     * Cookie validation key: from COOKIE_VALIDATION_KEY, or generated once and
     * persisted in runtime/ so a plain `cp .env.example .env` still works.
     */
    function gw_cookie_key(string $runtimePath): string
    {
        $key = gw_env('COOKIE_VALIDATION_KEY');
        if ($key) {
            return (string) $key;
        }
        $file = $runtimePath . '/cookie_validation.key';
        if (is_file($file) && ($stored = trim((string) file_get_contents($file))) !== '') {
            return $stored;
        }
        $key = bin2hex(random_bytes(32));
        @mkdir($runtimePath, 0775, true);
        file_put_contents($file, $key);
        @chmod($file, 0600);
        return $key;
    }
}

defined('YII_DEBUG') or define('YII_DEBUG', gw_env_bool('YII_DEBUG', false));
defined('YII_ENV') or define('YII_ENV', (string) gw_env('YII_ENV', 'prod'));
