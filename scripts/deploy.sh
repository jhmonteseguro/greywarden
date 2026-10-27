#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Deploy a release to this PRODUCTION checkout (COMPOSE_FILE includes
# docker-compose.prod.yml): verify the tag's PGP signature, back up, check the
# tag out, rebuild the images, restart and migrate.
#
#   scripts/deploy.sh v0.2.0-alpha                    a signed release tag (normal use)
#   scripts/deploy.sh --allow-unsigned <tag|commit>   anything else (not for releases)
#
# The backup taken first (scripts/backup.sh) is the way back if a migration ran.
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."

ALLOW_UNSIGNED=0; REF=""
for arg in "$@"; do
    case "$arg" in
        --allow-unsigned) ALLOW_UNSIGNED=1 ;;
        -h|--help) sed -n '3,10p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) echo "Unknown option: $arg (try --help)" >&2; exit 1 ;;
        *) [ -z "$REF" ] || { echo "Give one release to deploy" >&2; exit 1; }; REF=$arg ;;
    esac
done
[ -n "$REF" ] || { echo "Usage: scripts/deploy.sh <release-tag>   (try --help)" >&2; exit 1; }

command -v docker >/dev/null || { echo "docker is not installed" >&2; exit 1; }
[ -f .env ] || { echo "No .env found - set up production first (see README, Production)" >&2; exit 1; }
set -a; source .env; set +a
if [[ "${COMPOSE_FILE:-}" != *docker-compose.prod.yml* ]]; then
    echo "This checkout is not production: set COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml in .env" >&2; exit 1
fi
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "This checkout has local changes; production runs releases only (git status)" >&2; exit 1
fi

git fetch --quiet --tags origin
if ! COMMIT=$(git rev-parse -q --verify "$REF^{commit}"); then
    echo "Unknown release: $REF" >&2; exit 1
fi
if git rev-parse -q --verify "refs/tags/$REF" >/dev/null; then
    if ! git verify-tag "$REF" 2>/dev/null; then
        [ "$ALLOW_UNSIGNED" = 1 ] || { echo "Tag $REF has no valid PGP signature - refusing (--allow-unsigned to override)" >&2; exit 1; }
        echo "WARNING: deploying $REF without a valid signature" >&2
    fi
elif [ "$ALLOW_UNSIGNED" = 0 ]; then
    echo "$REF is not a release tag - refusing (--allow-unsigned to deploy a commit)" >&2; exit 1
fi

PREVIOUS=$(git describe --tags --always)
echo "== Deploying $REF ($(git rev-parse --short "$COMMIT")), currently $PREVIOUS"

DC="docker compose"
if [ -n "$($DC ps --status running -q db)" ]; then
    ./scripts/backup.sh
fi

git -c advice.detachedHead=false checkout --quiet "$COMMIT"
mkdir -p storage/attachments storage/branding && chmod 750 storage storage/attachments storage/branding
$DC build --pull
$DC up -d --wait
$DC exec -T php php yii migrate --interactive=0

echo
echo "Deployed $REF (was $PREVIOUS): http://127.0.0.1:${APP_PORT:-8080}"
echo "To go back: scripts/deploy.sh $PREVIOUS, then make restore FILE=<the backup above> if migrations ran."
