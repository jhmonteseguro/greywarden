#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Start Greywarden: build (if needed), start containers, wait for Composer,
# apply migrations and print the URL.
#
#   scripts/up.sh            start (migrations are idempotent)
#   scripts/up.sh --build    force a rebuild of the PHP image first
#   scripts/up.sh --seed     also load demo data (first run only, see README)
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."

BUILD=0; SEED=0
for arg in "$@"; do
    case "$arg" in
        --build) BUILD=1 ;;
        --seed)  SEED=1 ;;
        -h|--help) sed -n '3,8p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $arg (try --help)" >&2; exit 1 ;;
    esac
done

command -v docker >/dev/null || { echo "docker is not installed" >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo "Docker daemon is not running (sudo systemctl start docker)" >&2; exit 1; }

if [ ! -f .env ]; then
    cp .env.example .env
    chmod 600 .env
    echo "Created .env from .env.example"
fi
set -a; source .env; set +a

# Create the upload folders as you; Docker would create a missing one as root
mkdir -p storage/attachments storage/branding && chmod 750 storage storage/attachments storage/branding

DC="docker compose"
if [ "$BUILD" = 1 ]; then
    $DC up -d --build
else
    $DC up -d          # builds the PHP image only if it doesn't exist yet
fi

echo -n "Waiting for PHP dependencies"
for _ in $(seq 1 120); do
    # Installed, and no container is still running composer install (docker/php/entrypoint.sh)
    if $DC exec -T php sh -c 'test -f vendor/autoload.php && flock -n runtime/.composer.lock true' 2>/dev/null; then
        echo " ready."; break
    fi
    echo -n "."; sleep 2
done
$DC exec -T php test -f vendor/autoload.php 2>/dev/null \
    || { echo " timed out (see: docker compose logs php)" >&2; exit 1; }

echo "Applying migrations..."
$DC exec -T php php yii migrate --interactive=0

if [ "$SEED" = 1 ] && [[ "${COMPOSE_FILE:-}" == *docker-compose.prod.yml* ]]; then
    echo "Not loading demo data: this is a production checkout." >&2; SEED=0
fi
if [ "$SEED" = 1 ]; then
    echo "Loading demo data..."
    $DC exec -T php php yii seed/demo --interactive=0
fi

$DC ps
echo
echo "Greywarden is up: http://127.0.0.1:${APP_PORT:-8080}"
