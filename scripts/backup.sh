#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Back up the Greywarden database to backups/<db>-<timestamp>.sql.gz and the
# uploaded files (attachments, logo) to backups/attachments-<timestamp>.tar.gz.
# Starts the db container first if it isn't running (it is left running).
#
#   scripts/backup.sh          write a consistent dump (--single-transaction)
#   make restore FILE=backups/greywarden-YYYYMMDD-HHMMSS.sql.gz   to restore
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."

for arg in "$@"; do
    case "$arg" in
        -h|--help) sed -n '3,8p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $arg (try --help)" >&2; exit 1 ;;
    esac
done

command -v docker >/dev/null || { echo "docker is not installed" >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo "Docker daemon is not running (sudo systemctl start docker)" >&2; exit 1; }
[ -f .env ] || { echo "No .env found - nothing to back up yet (run scripts/up.sh first)" >&2; exit 1; }
set -a; source .env; set +a

DC="docker compose"
if [ -z "$($DC ps --status running -q db)" ]; then
    echo "Starting the db container..."
    $DC up -d --wait db
fi

BACKUP_DIR="${BACKUP_DIR:-backups}"
mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
FILE="$BACKUP_DIR/${DB_NAME:-greywarden}-$STAMP.sql.gz"

# Dumps contain password hashes and audit data: keep them owner-only, and only
# move the file into place once the dump has completed successfully.
umask 077
TMP="$FILE.partial"
trap 'rm -f "$TMP"' EXIT

# Runs inside the db container with its own MARIADB_* env (no secrets on the host command line)
$DC exec -T db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers --events "$MARIADB_DATABASE"' \
    | gzip > "$TMP"
mv "$TMP" "$FILE"
echo "Backup written: $FILE ($(du -h "$FILE" | cut -f1))"

# Uploaded files the database rows point to (restore by extracting into the project root)
DIRS=()
for dir in storage/attachments storage/branding; do
    if [ -d "$dir" ] && [ -n "$(ls -A "$dir")" ]; then DIRS+=("$dir"); fi
done
if [ ${#DIRS[@]} -gt 0 ]; then
    FILES="$BACKUP_DIR/attachments-$STAMP.tar.gz"
    TMP="$FILES.partial"
    tar -czf "$TMP" "${DIRS[@]}"
    mv "$TMP" "$FILES"
    echo "Backup written: $FILES ($(du -h "$FILES" | cut -f1))"
fi
