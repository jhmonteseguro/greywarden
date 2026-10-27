#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Stop private material reaching the repository. Run by the git hooks in
# .githooks (install them with `make hooks`) and before every release.
#
#   scripts/leak-check.sh                   every file in the index (pre-commit)
#   scripts/leak-check.sh --tree <rev>      every file in a commit (pre-push, releases)
#   scripts/leak-check.sh --message <file>  a commit message (commit-msg)
#   scripts/leak-check.sh --log <range>     the messages of a range of commits (pre-push)
#
# Files fail on their path (.env, dumps, storage/, backups/, AI-assistant and
# editor files) or their content: a term from your private denylist, or a
# secret value from .env. The denylist is one term per line (case-insensitive,
# # for comments) and lives outside the repository: $GREYWARDEN_DENYLIST,
# default ~/.config/greywarden/denylist. Messages fail on AI-attribution
# trailers. Findings show the file and match count only, never the matched text.
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

MODE=index; ARG=""
case "${1:-}" in
    "") ;;
    --tree|--message|--log)
        [ -n "${2:-}" ] || { echo "$1 needs an argument (try --help)" >&2; exit 2; }
        MODE=${1#--}; ARG=$2 ;;
    -h|--help) sed -n '3,17p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1 (try --help)" >&2; exit 2 ;;
esac

DENYLIST="${GREYWARDEN_DENYLIST:-$HOME/.config/greywarden/denylist}"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
FOUND=0
fail() { echo "leak-check: $*" >&2; FOUND=1; }

# Paths that must never be committed (extended regular expressions, matched against the whole path)
FORBIDDEN_PATHS='(^|/)\.env(\..*)?$|(^|/)(backups|storage)/|\.(sql|sql\.gz|dump|pem|key|p12|pfx)$|(^|/)id_(rsa|ecdsa|ed25519)|'\
'(^|/)(CLAUDE|CLAUDE\.local|AGENTS|GEMINI)\.md$|(^|/)\.(claude|gemini|cursor)/|(^|/)\.(cursorrules|windsurfrules)$|(^|/)\.aider|'\
'(^|/)\.github/copilot-instructions\.md$|^docs/initial-prompts\.txt$|(^|/)\.(vscode|idea)/|\.code-workspace$'
ALLOWED_PATHS='(^|/)\.env\.example$'

# AI-attribution lines that must not appear in commit messages
ATTRIBUTION='co-authored-by:.*(claude|anthropic|copilot|chatgpt|openai|gemini|cursor|aider)|claude-session:|claude\.(ai|com)|'\
'anthropic\.com|(generated|written|created) (with|by|using) .*(claude|chatgpt|copilot|gemini|\<ai\>|llm)|\<ai[- ](generated|written|assisted)\>'

check_message() { # check_message <label> <message file>
    # Comments and the diff below the scissors line (commit -v) are not part of the message
    sed -e '/^# -\{24\} >8 -\{24\}$/,$d' -e '/^#/d' "$2" > "$TMP/msg"
    if grep -q -i -E "$ATTRIBUTION" "$TMP/msg"; then
        fail "$1: the message credits an AI assistant; remove that line"
    fi
}

if [ "$MODE" = message ]; then
    check_message "commit message" "$ARG"
    exit "$FOUND"
fi
if [ "$MODE" = log ]; then
    for commit in $(git rev-list "$ARG"); do
        git log -1 --format=%B "$commit" > "$TMP/log"
        check_message "commit $(git rev-parse --short "$commit")" "$TMP/log"
    done
    exit "$FOUND"
fi

# ---------------------------------------------------------------- files
if [ "$MODE" = tree ]; then
    REV=$(git rev-parse --verify -q "$ARG^{commit}") || { echo "Unknown commit: $ARG" >&2; exit 2; }
    git ls-tree -r --name-only "$REV" > "$TMP/paths"
    GREP_TARGET=("$REV")
    LABEL="commit $(git rev-parse --short "$REV")"
else
    git ls-files --cached > "$TMP/paths"
    REV=""
    GREP_TARGET=(--cached)
    LABEL="staged files"
fi

while IFS= read -r path; do
    fail "$LABEL: $path must not be committed (git rm --cached '$path')"
done < <(grep -E "$FORBIDDEN_PATHS" "$TMP/paths" | grep -v -E "$ALLOWED_PATHS" || true)

# Content: private denylist terms and secret values from .env, as fixed strings
grep_terms() { # grep_terms <what> <patterns file>
    [ -s "$2" ] || return 0
    # -c prints path:count (tree: rev:path:count) so the matched text is never shown
    while IFS= read -r hit; do
        [ -n "$hit" ] && fail "$LABEL: ${hit#"$REV":} line(s) contain $1"
    done < <(git grep -I -i -c -F -f "$2" "${GREP_TARGET[@]}" -- . || true)
}

if [ -f "$DENYLIST" ]; then
    sed -e 's/#.*//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e '/^$/d' "$DENYLIST" > "$TMP/deny"
    grep_terms "a term from your denylist ($DENYLIST)" "$TMP/deny"
else
    echo "leak-check: no denylist at $DENYLIST; checking paths and .env secrets only" >&2
fi

if [ -f .env ]; then
    # Values of secret-looking keys, except the public defaults that .env.example ships with
    env_values() {
        sed -n -E 's/^[[:space:]]*(export[[:space:]]+)?[A-Z0-9_]*(PASSWORD|TOKEN|SECRET|_KEY)[A-Z0-9_]*=//p' "$1" \
            | sed -E -e 's/^"(.*)"$/\1/' -e "s/^'(.*)'\$/\\1/" -e 's/[[:space:]]+$//'
    }
    env_values .env | awk 'length($0) >= 8' | sort -u > "$TMP/env"
    if [ -f .env.example ]; then
        env_values .env.example | sort -u > "$TMP/example"
        comm -23 "$TMP/env" "$TMP/example" > "$TMP/secrets"
    else
        cp "$TMP/env" "$TMP/secrets"
    fi
    grep_terms "a secret value from .env" "$TMP/secrets"
fi

exit "$FOUND"
