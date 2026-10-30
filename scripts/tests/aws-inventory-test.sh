#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Tests for scripts/aws-inventory.sh against a fake AWS CLI (aws-inventory/aws,
# answering from aws-inventory/responses.json; --query is applied with Python's
# jmespath). No AWS credentials or network are used. The CSV must match
# src/tests/_data/cloud/aws-inventory.csv, which CloudImportTest imports.
#
#   scripts/tests/aws-inventory-test.sh
# ---------------------------------------------------------------------------
set -uo pipefail
SRC="$(cd "$(dirname "$0")/../.." && pwd)"
FAKE="$SRC/scripts/tests/aws-inventory"
SCRIPT="$SRC/scripts/aws-inventory.sh"
EXPECTED="$SRC/src/tests/_data/cloud/aws-inventory.csv"

if ! python3 -c 'import jmespath' 2>/dev/null; then
    echo "SKIP: python3 with jmespath is needed for the fake AWS CLI"; exit 0
fi
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
export PATH="$FAKE:$PATH" FAKE_AWS_RESPONSES="$FAKE/responses.json" FAKE_AWS_LOG="$WORK/calls"
unset AWS_PROFILE AWS_REGION

PASSED=0; FAILED=0
pass() { PASSED=$((PASSED + 1)); }
failed() { FAILED=$((FAILED + 1)); echo "FAIL: $*"; }
# run <command...>: output in $WORK/out, calls in $WORK/calls, exit status in $STATUS
run() { : >"$WORK/calls"; "$@" >"$WORK/out" 2>&1; STATUS=$?; }
expect_status() { if [ "$STATUS" = "$2" ]; then pass; else failed "$1: exit $STATUS, expected $2"; sed 's/^/    /' "$WORK/out"; fi; }
output_has() { if grep -q -F -- "$2" "$WORK/out"; then pass; else failed "$1: output lacks '$2'"; sed 's/^/    /' "$WORK/out"; fi; }
output_lacks() { if grep -q -F -- "$2" "$WORK/out"; then failed "$1: output shows '$2'"; else pass; fi; }
calls_lack() { if grep -q -e "$2" "$WORK/calls"; then failed "$1: a call matches '$2'"; else pass; fi; }

# Every Region the account has, the default profile
run "$SCRIPT" --output "$WORK/aws.csv"
expect_status "full run" 0
if diff -u "$EXPECTED" "$WORK/aws.csv" >"$WORK/diff"; then pass; else failed "CSV differs from $EXPECTED"; cat "$WORK/diff"; fi
output_has "identity" "Profile default: account 111122223333 as arn:aws:sts::111122223333:assumed-role/ReadOnlyAccess/jdoe"
output_has "Regions from describe-regions" "Regions: eu-west-2 us-east-1"
output_has "count" "Wrote 52 resources to $WORK/aws.csv"
output_has "per type" "2 kms:key"
output_has "denied call reported" "us-east-1	secretsmanager list-secrets	An error occurred (AccessDeniedException)"
output_lacks "missing endpoint not reported" "codecommit list-repositories"
output_has "next step" "make import-cloud FILE=$WORK/aws.csv PROVIDER=aws AS=<username> DRY_RUN=1"
calls_lack "only the default profile" "--profile [^d]"
calls_lack "read-only calls" " \(create\|delete\|put\|update\|modify\)-"
calls_lack "global services once" "--region us-east-1 --output text iam"
if [ "$(stat -c %a "$WORK/aws.csv")" = 600 ]; then pass; else failed "CSV is not owner-only"; fi

# Another profile, chosen Regions, default file name in the current directory
(cd "$WORK" && run "$SCRIPT" --profile prod --regions us-east-1 --jobs 1 && cp "$WORK/out" "$WORK/out.prod" && echo "$STATUS" >"$WORK/status.prod")
cp "$WORK/out.prod" "$WORK/out"; STATUS=$(cat "$WORK/status.prod")
expect_status "--profile prod" 0
CSV="$WORK/aws-prod-111122223333-$(date +%Y%m%d).csv"
if [ -f "$CSV" ]; then pass; else failed "default file name $CSV missing"; ls "$WORK"; fi
if grep -q ',lambda:function,us-east-1,' "$CSV" && ! grep -v ',s3:bucket,' "$CSV" | grep -q ',eu-west-2,'; then pass; else failed "--regions not applied"; cat "$CSV"; fi
calls_lack "prod profile only" "--profile default"
calls_lack "no Region lookup with --regions" "describe-regions"
if grep -q -- "--profile prod --region eu-west-1 --output text sts" "$WORK/calls"; then pass; else failed "profile's Region not used for sts"; cat "$WORK/calls"; fi

# Profiles: list and menu
run "$SCRIPT" --list-profiles
expect_status "--list-profiles" 0
output_has "profiles listed" "broken"
: >"$WORK/calls"; printf '2\n' | "$SCRIPT" --pick --regions eu-west-2 --output "$WORK/pick.csv" >"$WORK/out" 2>&1; STATUS=$?
expect_status "--pick" 0
output_has "picked profile" "Profile prod:"

# Errors
run "$SCRIPT" --profile nope --output "$WORK/nope.csv"
expect_status "unknown profile" 1
output_has "unknown profile message" "Cannot use profile 'nope'"
output_has "SSO hint" "aws sso login --profile nope"
if [ ! -e "$WORK/nope.csv" ]; then pass; else failed "CSV written for a failed run"; fi
FAKE_AWS_VERSION="aws-cli/1.33.0 Python/3.12" run "$SCRIPT"
expect_status "CLI v1" 1
output_has "v2 required" "AWS CLI v2 is required"
run "$SCRIPT" --jobs 0
expect_status "--jobs 0" 1
run "$SCRIPT" --bogus
expect_status "unknown option" 1
output_has "unknown option message" "Unknown option: --bogus"
run "$SCRIPT" --help
expect_status "--help" 0
output_has "help text" "scripts/aws-inventory.sh --pick"
output_lacks "help stops before the code" "set -euo"

echo "aws-inventory tests: $PASSED passed, $FAILED failed"
[ "$FAILED" -eq 0 ]
