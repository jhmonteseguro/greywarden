#!/bin/sh
# Prepare the Yii application (bind-mounted in dev, built in for prod), then
# hand off to php-fpm.
set -e
cd /var/www/html

mkdir -p runtime/sessions web/assets
chmod 775 runtime web/assets 2>/dev/null || true
chmod 770 runtime/sessions 2>/dev/null || true
if [ ! -w web/assets ]; then
    echo "[greywarden] web/assets is not writable by $(id -un) - asset bundles cannot be published" >&2
fi

if [ ! -f vendor/autoload.php ] && [ -f composer.json ]; then
    echo "[greywarden] vendor/ missing - running composer install..."
    composer install --no-interaction --prefer-dist --no-progress
fi

exec "$@"
