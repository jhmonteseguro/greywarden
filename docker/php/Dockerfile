# Greywarden PHP-FPM image (PHP 8.3). Build context: the repository root.
#
#   target dev  (docker-compose.yml, the default): tooling only; ./src is bind-mounted
#               and Composer installs the dependencies into it on first start.
#   target prod (docker-compose.prod.yml): the source and its --no-dev dependencies
#               are built into the image; opcache never re-checks files.
FROM php:8.3-fpm-bookworm AS base

ARG UID=1000
ARG GID=1000

# PHP extensions (installer handles build deps + cleanup)
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl gd zip opcache bcmath exif

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Run everything as the host user so files created in the bind mount
# (vendor/, runtime/, web/assets/, gii output) are owned by you.
RUN groupadd --non-unique -g ${GID} app \
    && useradd --non-unique -u ${UID} -g ${GID} -m -s /bin/bash app

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-greywarden.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-greywarden.conf
COPY docker/php/entrypoint.sh /usr/local/bin/greywarden-entrypoint
RUN chmod +x /usr/local/bin/greywarden-entrypoint

ENV COMPOSER_HOME=/home/app/.composer \
    COMPOSER_CACHE_DIR=/home/app/.composer/cache

WORKDIR /var/www/html
USER app

ENTRYPOINT ["greywarden-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------- production
FROM base AS prod

USER root
# zzz: loaded after zz-greywarden.ini, so these settings win
COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zzz-greywarden-prod.ini
USER app

# Dependencies first, so a code-only change reuses this layer
COPY --chown=app:app src/composer.json src/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-autoloader --no-scripts \
    && rm -rf /home/app/.composer/cache
COPY --chown=app:app src/ ./
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p runtime/sessions web/assets

# ---------------------------------------------------------------- development (default target)
FROM base AS dev
