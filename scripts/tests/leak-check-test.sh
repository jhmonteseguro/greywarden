#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Tests for scripts/leak-check.sh and the hooks in .githooks, in throwaway git
# repositories. Your git config, keyring and denylist are never used: signing
# tests run with a temporary GPG key (skipped when gpg is not installed).
#
#   scripts/tests/leak-check-test.sh
# ---------------------------------------------------------------------------
set -uo pipefail
SRC="$(cd "$(dirname "$0")/../.." && pwd)"
WORK=$(mktemp -d)
export GIT_CONFIG_GLOBAL="$WORK/gitconfig" GIT_CONFIG_NOSYSTEM=1 GNUPGHOME="$WORK/gnupg"
export GREYWARDEN_DENYLIST="$WORK/denylist"
cleanup() { command -v gpgconf >/dev/null && gpgconf --kill gpg-agent 2>/dev/null; rm -rf "$WORK"; }
trap cleanup EXIT

git config --global user.name "Test Author"
git config --global user.email test@example.invalid
git config --global init.defaultBranch main
git config --global advice.detachedHead false

PASSED=0; FAILED=0
pass() { PASSED=$((PASSED + 1)); }
failed() { FAILED=$((FAILED + 1)); echo "FAIL: $*"; }
# expect_ok|expect_refused <description> <command...>: output goes to $WORK/out
expect_ok() { local d=$1; shift; if "$@" >"$WORK/out" 2>&1; then pass; else failed "$d (refused)"; sed 's/^/    /' "$WORK/out"; fi; }
expect_refused() { local d=$1; shift; if "$@" >"$WORK/out" 2>&1; then failed "$d (allowed)"; else pass; fi; }
output_has() { if grep -q -F -- "$2" "$WORK/out"; then pass; else failed "$1: output lacks '$2'"; sed 's/^/    /' "$WORK/out"; fi; }
output_lacks() { if grep -q -i -F -- "$2" "$WORK/out"; then failed "$1: output shows '$2'"; else pass; fi; }

new_repo() { # new_repo <dir>: a repository using this checkout's leak check and hooks
    rm -rf "$1"; mkdir -p "$1/scripts"
    cp "$SRC/scripts/leak-check.sh" "$1/scripts/"
    cp -r "$SRC/.githooks" "$1/"
    git -C "$1" init -q
    git -C "$1" config core.hooksPath .githooks
    git -C "$1" add -A && git -C "$1" commit -q -m "Initial commit"
}
commit_file() { # commit_file <path> <content> [git commit options...]
    local path=$1 content=$2; shift 2
    mkdir -p "$(dirname "$path")" && printf '%s\n' "$content" > "$path"
    git add -f -- "$path" && git commit -q -m "Add $path" "$@"
}

printf '# private terms\nAcme Widgets\n\n  secretproject  # trailing comment\n' > "$GREYWARDEN_DENYLIST"
R="$WORK/repo"
new_repo "$R"
cd "$R" || exit 1

# ---------------------------------------------------------------- options
expect_refused "unknown option" scripts/leak-check.sh --bogus
expect_refused "--tree without a commit" scripts/leak-check.sh --tree
expect_ok "--help" scripts/leak-check.sh --help
output_has "--help" "--tree <rev>"
expect_refused "--tree with an unknown commit" scripts/leak-check.sh --tree no-such-ref

# ---------------------------------------------------------------- paths
expect_ok "an ordinary file" commit_file src/app.php '<?php echo "hello";'
for path in .env config/.env .env.production CLAUDE.md docs/AGENTS.md .claude/settings.json .cursorrules \
            .github/copilot-instructions.md backups/greywarden.sql.gz storage/attachments/ab/abc dump.sql \
            certs/server.key docs/initial-prompts.txt; do
    expect_refused "committing $path" commit_file "$path" "x"
    output_has "committing $path" "$path must not be committed"
    git reset -q --hard
    git clean -q -fdx
done
expect_ok ".env.example is allowed" commit_file .env.example "DB_PASSWORD=change-me"

# ---------------------------------------------------------------- content: denylist
expect_refused "a denylisted term in another case" commit_file README.md "Built for ACME widgets Ltd"
output_has "denylisted term" "README.md:1 line(s) contain a term from your denylist"
output_lacks "denylisted term" "acme widgets ltd"
git reset -q --hard
expect_refused "a denylisted term after a comment is stripped" commit_file notes.txt "the SecretProject plan"
git reset -q --hard
expect_ok "the comment text in the denylist is not a term" commit_file notes.txt "trailing comment"

mv "$GREYWARDEN_DENYLIST" "$WORK/denylist.off"
expect_ok "no denylist: only a warning" commit_file other.txt "acme widgets"
output_has "no denylist" "no denylist at"
mv "$WORK/denylist.off" "$GREYWARDEN_DENYLIST"
git reset -q --hard HEAD~1

# ---------------------------------------------------------------- content: .env secrets
cat > .env <<'EOF'
DB_PASSWORD="s3cr3t-db-pass"
export CLOUDFLARE_API_TOKEN=cf-token-1234567890
VIRUSTOTAL_API_KEY='vt-key-abcdefgh'
DB_ROOT_PASSWORD=change-me
SHORT_SECRET=abc
APP_NAME=NotASecretValue
EOF
expect_refused "a .env password in a committed file" commit_file config.php "'password' => 's3cr3t-db-pass',"
output_has ".env password" "config.php:1 line(s) contain a secret value from .env"
output_lacks ".env password" "s3cr3t-db-pass"
git reset -q --hard
expect_refused "an exported .env token" commit_file a.txt "token cf-token-1234567890"
git reset -q --hard
expect_refused "a single-quoted .env key" commit_file b.txt "vt-key-abcdefgh"
git reset -q --hard
expect_ok "a default from .env.example is public" commit_file c.txt "change-me"
expect_ok "short values and non-secret keys are ignored" commit_file d.txt "abc NotASecretValue"
rm .env

# ---------------------------------------------------------------- commit messages
echo change >> src/app.php && git add src/app.php
for message in 'Fix login\n\nCo-Authored-By: Claude Opus <noreply@anthropic.com>' \
               'Fix login\n\nClaude-Session: https://claude.ai/code/session_x' \
               'Fix login (generated with Claude Code)' \
               'AI-generated refactor' \
               'Fix login\n\nCo-authored-by: GitHub Copilot'; do
    expect_refused "message: $message" git commit -q -m "$(printf '%b' "$message")"
    output_has "message: $message" "the message credits an AI assistant"
done
printf 'Fix login\n# Co-Authored-By: Claude (a comment, removed by git)\n' > "$WORK/msg"
expect_ok "AI mentions in comment lines are ignored" scripts/leak-check.sh --message "$WORK/msg"
printf 'Fix login\n\n# ------------------------ >8 ------------------------\n+Co-Authored-By: Claude\n' > "$WORK/msg"
expect_ok "the diff below the scissors line is ignored" scripts/leak-check.sh --message "$WORK/msg"
expect_ok "an ordinary message" git commit -q -m "Generate monthly reports; add Aidan as co-owner"

# ---------------------------------------------------------------- --tree and --log on history
git commit -q --allow-empty --no-verify -m "$(printf 'Tidy up\n\nCo-Authored-By: Claude <noreply@anthropic.com>')"
expect_refused "--log finds attribution in history" scripts/leak-check.sh --log HEAD~1..HEAD
expect_ok "--log on a clean range" scripts/leak-check.sh --log HEAD~2..HEAD~1
commit_file CLAUDE.md "agent notes" --no-verify
expect_refused "--tree finds a committed agent file" scripts/leak-check.sh --tree HEAD
output_has "--tree" "CLAUDE.md must not be committed"
expect_ok "--tree on an earlier, clean commit" scripts/leak-check.sh --tree HEAD~2
git reset -q --hard HEAD~2

# ---------------------------------------------------------------- pre-push
PRIVATE="$WORK/someone/greywarden-fork.git" PUBLIC="$WORK/jhmonteseguro/greywarden.git"
mkdir -p "$WORK/someone" "$WORK/jhmonteseguro"
git init -q --bare "$PRIVATE" && git init -q --bare "$PUBLIC"
git remote add private "$PRIVATE" && git remote add public "$PUBLIC"
expect_ok "push clean, unsigned commits to a private remote" git push -q private main
commit_file leak.txt "for acme widgets" --no-verify
expect_refused "push a commit with a denylisted term" git push -q private main
output_has "pre-push" "nothing was pushed"
git reset -q --hard HEAD~1
commit_file leaked-then-removed.txt "for acme widgets" --no-verify
git rm -q leaked-then-removed.txt && git commit -q -m "Remove it"
expect_refused "push history that leaked and was cleaned up later" git push -q private main
git reset -q --hard HEAD~2
git commit -q --allow-empty --no-verify -m "$(printf 'Tidy up\n\nClaude-Session: https://claude.ai/code/x')"
expect_refused "push a message with AI attribution" git push -q private main
git reset -q --hard HEAD~1
expect_refused "push unsigned commits to the public repository" git push -q public main
output_has "unsigned public push" "has no valid PGP signature"

if command -v gpg >/dev/null; then
    mkdir -m 700 "$GNUPGHOME"
    gpg --batch --quiet --passphrase '' --quick-gen-key "Test Signer <signer@example.invalid>" ed25519 sign never >/dev/null 2>&1
    KEY=$(gpg --list-secret-keys --with-colons 2>/dev/null | awk -F: '/^fpr/ {print $10; exit}')
    git config user.signingkey "$KEY"
    git checkout -q --orphan release && git commit -q -S -m "Initial public release"
    expect_ok "push a signed commit to the public repository" git push -q public release:main
    git tag lightweight
    expect_refused "push a lightweight tag to the public repository" git push -q public lightweight
    output_has "lightweight tag" "is not a signed annotated tag"
    git tag -a -m "Unsigned" v0.0.1
    expect_refused "push an unsigned annotated tag" git push -q public v0.0.1
    output_has "unsigned tag" "tag v0.0.1 has no valid PGP signature"
    git tag -s -m "Signed" v0.1.0
    expect_ok "push a signed tag" git push -q public v0.1.0
    git commit -q --allow-empty -m "Unsigned follow-up"
    expect_refused "push an unsigned commit on top of a signed one" git push -q public release:main
    git config greywarden.signedRemote greywarden-fork.git
    expect_refused "greywarden.signedRemote picks the signed remote" git push -q private release:signed
    git config greywarden.signedRemote nothing-matches
    expect_ok "other remotes take unsigned commits" git push -q public release:main
    git push -q public release:old
    expect_ok "deleting a remote branch" git push -q public :old
else
    echo "SKIP: gpg is not installed; signed push tests not run"
fi

echo "leak-check tests: $PASSED passed, $FAILED failed"
[ "$FAILED" = 0 ]
