#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Stop Greywarden.
#
#   scripts/down.sh            stop and remove containers (data is kept)
#   scripts/down.sh --wipe     also delete the db-data and composer-cache
#                              volumes (destroys ALL data, asks first)
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."

WIPE=0
for arg in "$@"; do
    case "$arg" in
        --wipe) WIPE=1 ;;
        -h|--help) sed -n '3,7p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $arg (try --help)" >&2; exit 1 ;;
    esac
done

if [ "$WIPE" = 1 ]; then
    read -r -p "This will DELETE the database volume and all data. Type 'yes' to continue: " ans
    [ "$ans" = "yes" ] || { echo "Aborted."; exit 1; }
    docker compose down --volumes --remove-orphans
    echo "Greywarden stopped and volumes removed."
else
    docker compose down --remove-orphans
    echo "Greywarden stopped (data kept in the db-data volume)."
fi
