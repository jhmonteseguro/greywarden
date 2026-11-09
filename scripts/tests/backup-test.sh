#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Tests for scripts/backup.sh against a fake docker (backup/docker): where the
# backups go (BACKUP_DIR, the Admin > Settings > Backups directory, or backups/),
# directories with spaces, ~/ and shell characters in the setting. Runs a copy of
# the script in a throwaway checkout; no containers or databases are touched.
#
#   scripts/tests/backup-test.sh
# ---------------------------------------------------------------------------
set -uo pipefail
SRC="$(cd "$(dirname "$0")/../.." && pwd)"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
# A checkout whose path itself has a space in it
PROJ="$WORK/grey warden"
mkdir -p "$PROJ/scripts" "$PROJ/storage/attachments" "$PROJ/storage/branding" "$WORK/home"
cp "$SRC/scripts/backup.sh" "$PROJ/scripts/"
printf 'DB_NAME=greywarden\n' >"$PROJ/.env"
echo evidence >"$PROJ/storage/attachments/file.pdf"
export PATH="$SRC/scripts/tests/backup:$PATH" FAKE_DOCKER_LOG="$WORK/calls" HOME="$WORK/home"
unset BACKUP_DIR FAKE_BACKUP_SETTING FAKE_BACKUP_SETTING_FAIL

PASSED=0; FAILED=0
pass() { PASSED=$((PASSED + 1)); }
failed() { FAILED=$((FAILED + 1)); echo "FAIL: $*"; }
# run [VAR=value...]: runs the script; output in $WORK/out, exit status in $STATUS
run() { : >"$WORK/calls"; env "$@" "$PROJ/scripts/backup.sh" >"$WORK/out" 2>&1; STATUS=$?; }
expect_ok() { if [ "$STATUS" = 0 ]; then pass; else failed "$1: exit $STATUS"; sed 's/^/    /' "$WORK/out"; fi; }
# backed_up <what> <dir>: a dump and an attachments archive were written to <dir>, owner-only, and the output names them
backed_up() {
    local dump files
    dump=$(find "$2" -maxdepth 1 -name 'greywarden-*.sql.gz' 2>/dev/null | head -1)
    files=$(find "$2" -maxdepth 1 -name 'attachments-*.tar.gz' 2>/dev/null | head -1)
    if [ -n "$dump" ] && [ "$(gunzip -c "$dump")" = "FAKE DUMP" ]; then pass; else failed "$1: no dump in '$2'"; sed 's/^/    /' "$WORK/out"; return; fi
    if [ -n "$files" ] && tar -tzf "$files" | grep -q '^storage/attachments/file.pdf$'; then pass; else failed "$1: no attachments archive in '$2'"; fi
    if [ "$(stat -c %a "$dump")" = 600 ]; then pass; else failed "$1: dump is not owner-only"; fi
    if grep -q -F "Backup written: $dump (" "$WORK/out"; then pass; else failed "$1: output does not name '$dump'"; fi
    if ! find "$2" -name '*.partial' | grep -q .; then pass; else failed "$1: partial file left behind"; fi
    if grep -q -F "To restore: make restore FILE=$(printf %q "$dump")" "$WORK/out"; then pass; else failed "$1: no restore hint"; fi
    rm -rf "${2:?}"
}

# Nothing set: backups/ in the checkout
run
expect_ok "default"
backed_up "default" "$PROJ/backups"
if grep -q "^QUERY SELECT COALESCE(value, '') FROM setting WHERE name = 'backup.path'$" "$WORK/calls"; then pass; else failed "setting not read"; cat "$WORK/calls"; fi

# Setting saved but empty: the default
run FAKE_BACKUP_SETTING=
expect_ok "empty setting"
backed_up "empty setting" "$PROJ/backups"

# Absolute directory with spaces, created if missing
run FAKE_BACKUP_SETTING="$WORK/NAS share/Greywarden backups"
expect_ok "absolute path with spaces"
backed_up "absolute path with spaces" "$WORK/NAS share/Greywarden backups"

# Relative to the checkout, with spaces
run FAKE_BACKUP_SETTING="off site/nightly"
expect_ok "relative path with spaces"
backed_up "relative path with spaces" "$PROJ/off site/nightly"

# ~/ is the home of the user running the script
run FAKE_BACKUP_SETTING="~/My Backups"
expect_ok "home path"
backed_up "home path" "$WORK/home/My Backups"

# A leading dash is a directory, not an option
run FAKE_BACKUP_SETTING="-backups"
expect_ok "leading dash"
backed_up "leading dash" "$PROJ/-backups"

# Shell characters are a literal directory name, never run
run FAKE_BACKUP_SETTING="\$(touch pwned) \`touch pwned\`; touch pwned"
expect_ok "shell characters"
if [ ! -e "$PROJ/pwned" ] && [ ! -e "$PROJ/scripts/pwned" ]; then pass; else failed "setting was evaluated by the shell"; fi
backed_up "shell characters" "$PROJ/\$(touch pwned) \`touch pwned\`; touch pwned"

# BACKUP_DIR takes precedence, and the setting is then not read
run BACKUP_DIR="$WORK/env dir" FAKE_BACKUP_SETTING="$WORK/setting dir"
expect_ok "BACKUP_DIR"
backed_up "BACKUP_DIR" "$WORK/env dir"
if [ ! -d "$WORK/setting dir" ] && ! grep -q '^QUERY' "$WORK/calls"; then pass; else failed "BACKUP_DIR did not take precedence"; fi

# No setting table yet (or the query fails): the default
run FAKE_BACKUP_SETTING_FAIL=1
expect_ok "setting unreadable"
backed_up "setting unreadable" "$PROJ/backups"
if ! grep -q "doesn't exist" "$WORK/out"; then pass; else failed "query error shown"; fi

# No uploaded files: only the dump
rm -rf "$PROJ/storage/attachments/file.pdf"
run
expect_ok "no attachments"
if [ -z "$(find "$PROJ/backups" -name 'attachments-*')" ] && [ -n "$(find "$PROJ/backups" -name 'greywarden-*.sql.gz')" ]; then pass; else failed "attachments archive written with nothing to archive"; fi

echo "backup.sh: $PASSED passed, $FAILED failed"
[ "$FAILED" = 0 ]
