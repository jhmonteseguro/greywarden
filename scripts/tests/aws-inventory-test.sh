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
command -v jq >/dev/null || { echo "SKIP: jq is needed by scripts/aws-inventory.sh"; exit 0; }
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
calls_have() { if grep -q -e "$2" "$WORK/calls"; then pass; else failed "$1: no call matches '$2'"; fi; }
# csv_has <what> <file> <ERE>: a CSV line matches
csv_has() { if grep -q -E -- "$3" "$2"; then pass; else failed "$1: no line of $2 matches '$3'"; fi; }
csv_lacks() { if grep -q -E -- "$3" "$2"; then failed "$1: a line of $2 matches '$3'"; else pass; fi; }

# Every Region the account has, the default profile
run "$SCRIPT" --output "$WORK/aws.csv"
expect_status "full run" 0
if diff -u "$EXPECTED" "$WORK/aws.csv" >"$WORK/diff"; then pass; else failed "CSV differs from $EXPECTED"; cat "$WORK/diff"; fi
output_has "identity" "Profile default: account 111122223333 as arn:aws:sts::111122223333:assumed-role/ReadOnlyAccess/jdoe"
output_has "Regions from describe-regions" "Regions: eu-west-2 us-east-1"
output_has "count" "Wrote 57 rows (resources and the account's settings) to $WORK/aws.csv"
output_has "per type" "2 kms:key"
output_has "denied call reported" "us-east-1	secretsmanager list-secrets	An error occurred (AccessDeniedException)"
output_lacks "missing endpoint not reported" "codecommit list-repositories"
output_lacks "no unexpected responses" "jq (unexpected response)"
output_lacks "settings never configured are not failures" "NoSuch"
output_has "unused default VPCs reported" "Left out the unused default VPCs (and their security group, network ACL and internet gateway) in: eu-west-2 us-east-1 (--all keeps them)"
output_has "next step" "make import-cloud FILE=$WORK/aws.csv PROVIDER=aws AS=<username> DRY_RUN=1"
calls_lack "only the default profile" "--profile [^d]"
calls_lack "read-only calls" " \(create\|delete\|put\|update\|modify\)-"
calls_lack "global services once" "--region us-east-1 --output json iam"
calls_have "per-bucket settings" "--region eu-west-2 --output json s3api get-public-access-block --bucket example-portal-uploads"
calls_have "bucket without a Region: home Region" "--region eu-west-2 --output json s3api get-bucket-acl --bucket example-old-logs"
calls_have "account Block Public Access" "s3control get-public-access-block --account-id 111122223333"
calls_have "trail status in its home Region" "--region eu-west-2 --output json cloudtrail get-trail-status"
calls_lack "AWS managed KMS keys are not looked into" "get-key-rotation-status --key-id aaaa1111"
calls_lack "no rotation status for a key pending deletion" "get-key-rotation-status --key-id cccc3333"
# Plumbing left out: unused default VPCs with their security group, ACL and gateway; default ACLs; rule-less default
# security groups; AWS service and IAM Identity Center roles; replication group members; terminated instances
for plumbing in vpc-0default igw-0default acl-0default sg-0default acl-0123456789abcdef9 sg-0123456789abcdef2 \
        AWSServiceRoleForECS AWSReservedSSO cache-0001-001 i-0a1b2c3d4e5f60003; do
    csv_lacks "left out: $plumbing" "$WORK/aws.csv" "$plumbing"
done
csv_has "account row" "$WORK/aws.csv" '^arn:aws:iam::111122223333:root,account,global,111122223333,example-prod,,'
csv_has "account: GuardDuty and EBS default encryption off in us-east-1" "$WORK/aws.csv" '""guardduty_off"":\[""us-east-1""\],""ebs_encryption_off"":\[""us-east-1""\]'
csv_has "public bucket" "$WORK/aws.csv" 'example-old-logs,,"\{""block_public_access"":""none"".*""policy_public"":true,""acl_public"":true'
csv_has "IMDSv1 and SSM OS" "$WORK/aws.csv" '""os"":""Ubuntu"",""os_version"":""22.04"",""ssm_managed"":true,""imds"":""optional""'
csv_has "open SSH" "$WORK/aws.csv" 'security-group/sg-0123456789abcdef1,.*""open_ingress"":\[""tcp/22""\]'
csv_has "credential report" "$WORK/aws.csv" 'user/break-glass,.*""admin"":true,""console"":true,""password_last_used"":""2024-02-01"",""mfa"":false,""access_keys"":\[\{""last_rotated"":""2024-01-01"",""last_used"":null\}\]'
csv_has "cross-account trust" "$WORK/aws.csv" '""trusted_unconditional"":\[""arn:aws:iam::999988887777:root""\],""admin"":true'
csv_has "public function URL" "$WORK/aws.csv" '""url_auth"":""NONE""'
csv_has "listeners" "$WORK/aws.csv" '""listeners"":\[""HTTPS:443"",""HTTP:80 redirect""\]'
csv_has "detail call failed: row kept" "$WORK/aws.csv" '^arn:aws:lambda:us-east-1:111122223333:function:edge-auth,'

# --all keeps the plumbing
run "$SCRIPT" --all --output "$WORK/all.csv"
expect_status "--all" 0
output_lacks "--all: nothing reported as left out" "Left out"
for plumbing in vpc-0default00000euw2 igw-0default00000use1 acl-0123456789abcdef9 sg-0123456789abcdef2 AWSServiceRoleForECS cache-0001-001; do
    csv_has "--all keeps $plumbing" "$WORK/all.csv" "$plumbing"
done
csv_has "--all: the account still lists the unused default VPCs" "$WORK/all.csv" '""unused_default_vpcs"":\[""eu-west-2"",""us-east-1""\]'

# No usable network interface list: nothing is guessed to be unused
jq '.["eu-west-2/ec2.describe-network-interfaces"] = {"__error__": "An error occurred (UnauthorizedOperation) when calling DescribeNetworkInterfaces"}' \
    "$FAKE/responses.json" >"$WORK/no-enis.json"
FAKE_AWS_RESPONSES="$WORK/no-enis.json" run "$SCRIPT" --regions eu-west-2 --output "$WORK/no-enis.csv"
expect_status "no network interfaces" 0
csv_has "default VPC kept when usage is unknown" "$WORK/no-enis.csv" 'vpc-0default00000euw2'
csv_lacks "in-use unknown" "$WORK/no-enis.csv" 'security-group/sg-0123456789abcdef0,.*in_use'

# A credential report that is still being generated is waited for
jq '.["iam.generate-credential-report"] = {"State": "STARTED"}' "$FAKE/responses.json" >"$WORK/report.json"
mkdir -p "$WORK/fastbin" && printf '#!/bin/sh\nexit 0\n' >"$WORK/fastbin/sleep" && chmod +x "$WORK/fastbin/sleep"
PATH="$WORK/fastbin:$PATH" FAKE_AWS_RESPONSES="$WORK/report.json" run "$SCRIPT" --regions eu-west-2 --output "$WORK/report.csv"
expect_status "credential report pending" 0
if [ "$(grep -c 'generate-credential-report' "$WORK/calls")" = 10 ]; then pass; else failed "credential report not polled 10 times"; fi
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
if grep -q -- "--profile prod --region eu-west-1 --output json sts" "$WORK/calls"; then pass; else failed "profile's Region not used for sts"; cat "$WORK/calls"; fi

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
output_has "help mentions --all" "--all"

# jq is required
mkdir -p "$WORK/nojq"
ln -sf "$FAKE/aws" "$WORK/nojq/aws"
PATH="$WORK/nojq" run "$(command -v bash)" "$SCRIPT"
expect_status "no jq" 1
output_has "jq message" "jq is not installed"

echo "aws-inventory tests: $PASSED passed, $FAILED failed"
[ "$FAILED" -eq 0 ]
