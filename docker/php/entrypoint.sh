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

# php and scheduler share the bind-mounted code and start together: whichever gets
# the lock installs, the other waits and then finds vendor/ ready. (Production
# images have vendor/ built in, so they never get here.)
if [ ! -f vendor/autoload.php ] && [ -f composer.json ]; then
    (
        flock 9
        if [ ! -f vendor/autoload.php ]; then
            echo "[greywarden] vendor/ missing - running composer install..."
            composer install --no-interaction --prefer-dist --no-progress
        fi
    ) 9>runtime/.composer.lock
fi

exec "$@"
