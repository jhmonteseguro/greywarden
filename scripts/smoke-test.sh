#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Greywarden HTTP smoke test (needs only bash + curl on the host).
#
# Logs in as admin / analyst / viewer, GETs every index/create/view/update page,
# checks status codes (200 / 403 by role), scans responses for PHP errors and
# exercises create/update/archive/restore/delete via POST with CSRF tokens.
#
#   make test-all         runs this safely against the seeded TEST database (recommended)
#   SMOKE_DB_NAME=greywarden_test SMOKE_BASE_URL=http://127.0.0.1:8099 scripts/smoke-test.sh
#
# The smoke test writes throwaway records and audit rows, so it refuses to run
# against the live database named in .env unless SMOKE_ALLOW_LIVE_DB=1.
# Admin checks run as a temporary admin (smoke.admin.<pid>) created with
# `yii user/create-admin`, so the real admin account and its password are never
# touched. Its forced first-login password change is exercised, and the account
# is deleted on exit, even when the run fails or is interrupted.
# ---------------------------------------------------------------------------
set -uo pipefail
cd "$(dirname "$0")/.."
LIVE_DB=$(set -a; source .env; echo "${DB_NAME:-greywarden}")
set -a; source .env; set +a
# Overrides win over .env (which also sets DB_NAME)
DB_NAME="${SMOKE_DB_NAME:-$LIVE_DB}"
BASE_URL="${SMOKE_BASE_URL:-${BASE_URL:-}}"
if [[ "$DB_NAME" == "$LIVE_DB" && "${SMOKE_ALLOW_LIVE_DB:-0}" != 1 ]]; then
    echo "Refusing to run against the live database '$LIVE_DB' (it holds real data)." >&2
    echo "Use 'make test-all' (seeded test database), or set SMOKE_ALLOW_LIVE_DB=1 to override." >&2
    exit 2
fi
# yii commands run inside the php container must use the same database
PHP_EXEC=(docker compose exec -T -e DB_NAME="$DB_NAME" php)

BASE="${BASE_URL:-http://127.0.0.1:${APP_PORT:-8080}}"
WORK="src/runtime/smoke"          # scratch files (cookie jars, last response)
mkdir -p "$WORK"
PASS=0; FAIL=0
ERR_PATTERN='ErrorException|Stack trace|Database Exception|Invalid Configuration|Unknown Property|Unknown Method|Undefined variable|Undefined array key|Call to undefined|TypeError|ArgumentCountError|Integrity constraint'

ok()   { PASS=$((PASS+1)); }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL: $*"; }
jar()  { echo "$WORK/cookies-$1.txt"; }
reset_jar() { : > "$(jar "$1")"; }

# req <role> <method> <path> [field=value...]  -> sets CODE and BODY
req() {
    local role=$1 method=$2 path=$3; shift 3
    local args=(-s -o "$WORK/body.html" -w '%{http_code}' -b "$(jar "$role")" -c "$(jar "$role")" -X "$method")
    for d in "$@"; do args+=(--data-urlencode "$d"); done
    CODE=$(curl "${args[@]}" "$BASE$path")
    BODY="$WORK/body.html"
}

csrf() { # csrf <role> <path>
    req "$1" GET "$2"
    sed -n 's/.*<meta name="csrf-token" content="\([^"]*\)".*/\1/p' "$BODY" | head -1
}

check() { # check <role> <path> <expected-code>
    req "$1" GET "$2"
    if [[ "$CODE" != "$3" ]]; then bad "$1 GET $2 -> $CODE (expected $3)"; return; fi
    if grep -Eq "$ERR_PATTERN" "$BODY"; then bad "$1 GET $2 contains PHP error: $(grep -Eo "$ERR_PATTERN" "$BODY" | head -1)"; return; fi
    ok
}

post() { # post <role> <form-page> <action-path> <expected-code> <field=value>...
    local role=$1 page=$2 action=$3 expect=$4; shift 4
    local token; token=$(csrf "$role" "$page")
    req "$role" POST "$action" "_csrf=$token" "$@"
    if [[ "$CODE" != "$expect" ]]; then
        bad "$role POST $action -> $CODE (expected $expect)"
        grep -o 'invalid-feedback">[^<]*' "$BODY" | head -5 | sed 's/^/        /'
        return 1
    fi
    if grep -Eq "$ERR_PATTERN" "$BODY"; then bad "$role POST $action contains PHP error"; return 1; fi
    ok; return 0
}

login() { # login <role> <username> <password>
    reset_jar "$1"
    local token; token=$(csrf "$1" /login)
    req "$1" POST /login "_csrf=$token" "LoginForm[username]=$2" "LoginForm[password]=$3"
    [[ "$CODE" == "302" ]] && ok || bad "login $2 -> $CODE"
}

sql() { docker compose exec -T db mariadb -N -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "$1" 2>/dev/null | tr -d '\r'; }
last_id() { sql "SELECT MAX(id) FROM \`$1\`"; }

# id_of <table> <ref-column> <reference>
id_of() { sql "SELECT id FROM \`$1\` WHERE \`$2\` = '$3'"; }

echo "== Greywarden smoke test against $BASE (database $DB_NAME)"
# Only log lines written during this run count (older errors may be from the live app)
LOG_START=$(docker compose exec -T php sh -c 'test -f runtime/logs/app.log && wc -l < runtime/logs/app.log || echo 0' | tr -d '[:space:]')
R1=$(id_of risk reference RSK-0001); R2=$(id_of risk reference RSK-0002); R5=$(id_of risk reference RSK-0005); R13=$(id_of risk reference RSK-0013)
D1=$(id_of decision reference DEC-0001); D7=$(id_of decision reference DEC-0007); D11=$(id_of decision reference DEC-0011)
I1=$(id_of incident reference INC-0001); I13=$(id_of incident reference INC-0013); I17=$(id_of incident reference INC-0017); I19=$(id_of incident reference INC-0019)
B1=$(id_of intel_blocklist reference BLK-0001); B6=$(id_of intel_blocklist reference BLK-0006)
V1=$(id_of device asset_tag DEV-0001); V26=$(id_of device asset_tag DEV-0026); V38=$(id_of device asset_tag DEV-0038)
C1=$(id_of control reference CTL-0001); C5=$(id_of control reference CTL-0005); C8=$(id_of control reference CTL-0008); C18=$(id_of control reference CTL-0018)
REQ_ISO=$(sql "SELECT id FROM framework_requirement WHERE external_id='A.8.7'"); REQ_CE=$(sql "SELECT id FROM framework_requirement WHERE external_id='CE-UA-4'")
REQ_SOC2=$(sql "SELECT id FROM framework_requirement WHERE external_id='CC6.1'"); REQ_SOC2_A=$(sql "SELECT id FROM framework_requirement WHERE external_id='A1.2'")
REQ_NIST=$(sql "SELECT id FROM framework_requirement WHERE external_id='PR.AA-01'"); REQ_CAF=$(sql "SELECT id FROM framework_requirement WHERE external_id='B4.d'")
# Manual device checks in force, as Device[checks][<id>]=<result> fields
CHECKS=($(sql "SELECT id FROM control WHERE device_check='manual' AND status IN ('implementing','implemented') AND archived_at IS NULL ORDER BY id"))
checks() { local r=$1; for c in "${CHECKS[@]}"; do echo "Device[checks][$c]=$r"; done; }
if [[ -z "$R1" || -z "$I19" || -z "$V38" || -z "$B1" || -z "$C18" || -z "$REQ_ISO" ]]; then echo "Demo data missing - run: make seed"; exit 1; fi

# ---------------------------------------------------------------- temporary admin + cleanup
SMOKE_USER="smoke.user.$$"
ADMIN_USERNAME="smoke.admin.$$"
ADMIN_PASSWORD="Smoke!Admin-$(date +%s)-$RANDOM"

# Users cannot be hard-deleted in the UI by design, so the temporary accounts are removed with SQL
delete_user() { # delete_user <username>
    sql "DELETE a FROM auth_assignment a JOIN user u ON u.id = a.user_id WHERE u.username = '$1';
         DELETE FROM login_attempt WHERE username = '$1'; DELETE FROM user WHERE username = '$1'" >/dev/null
}
cleanup() {
    delete_user "$ADMIN_USERNAME"
    delete_user "$SMOKE_USER"
    sql "DELETE FROM login_attempt WHERE success = 0 AND username = 'viewer'" >/dev/null
    # Intel notes are append-only in the UI: remove the smoke ones and the throwaway indicator (audit rows stay)
    sql "DELETE FROM intel_note WHERE body LIKE 'Smoke test%'; DELETE FROM intel_indicator WHERE value = '192.0.2.99'" >/dev/null
    if [[ -n "$(sql "SELECT id FROM user WHERE username IN ('$ADMIN_USERNAME', '$SMOKE_USER')")" ]]; then
        echo "WARNING: could not remove temporary user(s) $ADMIN_USERNAME / $SMOKE_USER"
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if ! "${PHP_EXEC[@]}" php yii user/create-admin "$ADMIN_USERNAME" "$ADMIN_USERNAME@example.invalid" "$ADMIN_PASSWORD" --interactive=0 >/dev/null; then
    echo "Could not create the temporary admin user"; exit 1
fi

# ---------------------------------------------------------------- guests
reset_jar guest
check guest / 302
check guest /risks 302
check guest /login 200

# ---------------------------------------------------------------- admin: forced password change
echo "-- admin (forced password change)"
login admin "$ADMIN_USERNAME" "$ADMIN_PASSWORD"
REDIRECT=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$(jar admin)" "$BASE/")
if [[ "$REDIRECT" == *account/password* ]]; then
    ok
    TEMP_PW="Smoke!Test-$(date +%s)"
    post admin /account/password /account/password 302 \
        "PasswordForm[current_password]=$ADMIN_PASSWORD" "PasswordForm[new_password]=$TEMP_PW" "PasswordForm[new_password_repeat]=$TEMP_PW"
else
    bad "new admin was not sent to the forced password change ($REDIRECT)"
fi

# ---------------------------------------------------------------- page matrix
login analyst analyst "$DEMO_PASSWORD"
login viewer viewer "$DEMO_PASSWORD"

READ_PAGES=(
    / /search?q=RSK /search?q=LT- /search?q=phishing /account/profile /account/password
    /risks "/risks?filter=all" "/risks?filter=mine" "/risks?filter=review-overdue" "/risks?filter=treatment-overdue" "/risks?filter=archived"
    "/risks?filter=all&sort=-residual_rating&status=in_treatment&q=a" "/risks?filter=open&residual_likelihood=2&residual_impact=4"
    /risks/heatmap "/risks/heatmap?heatmap=inherent" /risks/$R1 /risks/$R2 /risks/$R5 /risks/$R13
    /decisions "/decisions?filter=proposed" "/decisions?filter=review-overdue" "/decisions?filter=mine" "/decisions?sort=-decision_date&status=approved" /decisions/$D1 /decisions/$D7 /decisions/$D11
    /incidents "/incidents?filter=all" "/incidents?filter=mine" "/incidents?filter=regulator-overdue" "/incidents?filter=regulator-pending"
    "/incidents?filter=all&sort=-severity" "/incidents?filter=all&month=$(date +%Y-%m)" "/incidents?filter=all&impact=confidentiality" /incidents/$I1 /incidents/$I13 /incidents/$I17 /incidents/$I19
    /devices "/devices?filter=active" "/devices?filter=mine" "/devices?filter=non-compliant" "/devices?filter=not-assessed" "/devices?filter=warranty" "/devices?filter=eol"
    "/devices?filter=active&control=$C5&result=fail" "/devices?filter=active&control=$C1&result=unknown" "/devices?filter=stale" "/devices?filter=all&sort=-last_seen_at"
    "/devices?filter=all&sort=-criticality&device_type=laptop" /devices/$V1 /devices/$V26 /devices/$V38
    "/?heatmap=inherent"
    # Control Register and frameworks
    /controls "/controls?filter=mine" "/controls?filter=planned" "/controls?filter=implemented" "/controls?filter=test-due-soon" "/controls?filter=test-overdue" "/controls?filter=weak"
    "/controls?filter=not-tested" "/controls?filter=target-overdue" "/controls?filter=device-checks" "/controls?filter=retired" "/controls?filter=archived"
    "/controls?requirement=A.8.7" "/controls?mitigation=M1032" "/controls?sort=-next_test_due&effectiveness=partial" "/controls?sort=effectiveness&q=backup"
    /controls/$C1 /controls/$C5 /controls/$C8 /controls/$C18 "/risks?filter=control-issues"
    /frameworks /frameworks/iso27001 /frameworks/cyber-essentials "/frameworks/iso27001?state=gap" "/frameworks/cyber-essentials?state=met"
    /frameworks/requirement/$REQ_ISO /frameworks/requirement/$REQ_CE "/search?q=passkeys" "/search?q=Annex"
    /frameworks/soc2 "/frameworks/soc2?state=met" /frameworks/requirement/$REQ_SOC2
    /frameworks/nist-csf "/frameworks/nist-csf?state=gap" /frameworks/requirement/$REQ_NIST
    /frameworks/ncsc-caf "/frameworks/ncsc-caf?state=met" /frameworks/requirement/$REQ_CAF
    /attack "/attack?layer=risks" "/attack?show=mapped" "/attack?layer=risks&show=mapped" /attack/T1566 /attack/T1566.001 /attack/T1656
    /attack/mitigations /attack/mitigations/M1032 /attack/mitigations/M1055
    "/incidents?filter=all&technique=T1566" "/risks?filter=all&technique=phishing" "/decisions?mitigation=M1032" "/search?q=credential"
    # Threat intel: only documentation / private indicators, so no lookup leaves the network
    /intel /intel/ip/203.0.113.66 /intel/ip/198.51.100.23 /intel/ip/10.20.30.40 /intel/ip/100.64.1.1 /intel/ip/fd00::5 /intel/asn/AS64511 /intel/asn/AS64512
    "/intel?sort=-last_score" /intel/blocklist "/intel/blocklist?kind=asn" "/intel/blocklist?filter=expired" "/intel/blocklist?filter=unblocked"
    "/intel/blocklist?filter=all&sort=-value&severity=critical" "/intel/blocklist?filter=expiring&q=DDoS" /intel/blocklist/$B1 /intel/blocklist/$B6 /intel/sources
    "/search?q=203.0.113.66" "/search?q=AS64511" "/search?q=bulletproof"
)
WRITE_PAGES=(/risks/create /risks/$R1/update /controls/create /controls/$C1/update /controls/$C5/update /decisions/create /decisions/$D1/update /incidents/create /incidents/$I1/update /devices/create /devices/$V1/update
    /intel/blocklist/create "/intel/blocklist/create?value=192.0.2.99" /intel/blocklist/$B1/update)
U2=$(id_of user username viewer)
ADMIN_PAGES=(/admin/user /admin/user/1 /admin/user/$U2 /admin/user/create /admin/user/$U2/update /admin/user/$U2/reset-password
    /admin/audit "/admin/audit?entity_type=risk&action=update" "/admin/audit?action=login_failed" /admin/audit/1
    /admin/lookup "/admin/lookup?type=department" "/admin/lookup?type=location" /admin/lookup/create /admin/lookup/1/update /admin/settings)

for role in admin analyst viewer; do
    echo "-- $role: read pages"
    for p in "${READ_PAGES[@]}"; do check "$role" "$p" 200; done
    echo "-- $role: write pages"
    for p in "${WRITE_PAGES[@]}"; do check "$role" "$p" "$([[ $role == viewer ]] && echo 403 || echo 200)"; done
    echo "-- $role: admin pages"
    for p in "${ADMIN_PAGES[@]}"; do check "$role" "$p" "$([[ $role == admin ]] && echo 200 || echo 403)"; done
done
check analyst /search?q=DEV-0001 302
check viewer "/controls?filter=test-due-soon" 200
grep -q 'data-value="due-soon"' "$BODY" && ok || bad "no due-soon control test flagged (demo data has one)"
check viewer / 200
grep -q 'gw-attention__group--control-tests-soon' "$BODY" && ok || bad "due-soon control tests missing from Needs attention"
check analyst /search?q=CTL-0001 302
check analyst /search?q=A.8.7 302
check analyst /search?q=CC6.1 302
check analyst /search?q=PR.AA-01 302
check analyst /search?q=B4.d 302
check viewer /frameworks/requirement/$REQ_SOC2_A 404   # SOC 2 Availability is out of scope until chosen
check viewer /frameworks/pci-dss 404
check viewer /frameworks/requirement/999999 404
check analyst /search?q=T1566.001 302
check analyst /attack/T9999 404
check viewer /attack/mitigations/M9999 404
check analyst /search?q=BLK-0001 302
check analyst "/intel?q=hxxp://203.0.113%5B.%5D66:443/x" 302
check analyst /intel/ip/999.1.1.1 404
check analyst /intel/ip/192.0.2.099 404
check analyst /intel/asn/AS99999999999 404

# ---------------------------------------------------------------- POST flows
echo "-- POST flows"
TODAY=$(date +%Y-%m-%d)
NOW=$(date +%Y-%m-%dT%H:%M)
RISK_CAT=$(sql "SELECT id FROM lookup WHERE type='risk_category' LIMIT 1")
DEC_CAT=$(sql "SELECT id FROM lookup WHERE type='decision_category' LIMIT 1")
INC_CAT=$(sql "SELECT id FROM lookup WHERE type='incident_category' LIMIT 1")
TECH=$(sql "SELECT id FROM attack_technique WHERE external_id='T1566.001'")
MITIG=$(sql "SELECT id FROM attack_mitigation WHERE external_id='M1017'")
ANALYST_ID=$(sql "SELECT id FROM user WHERE username='analyst'")
VIEWER_ID=$(sql "SELECT id FROM user WHERE username='viewer'")

# Risk create (analyst)
post analyst /risks/create /risks/create 302 \
    "Risk[title]=Smoke test risk" "Risk[status]=assessed" "Risk[category_id]=$RISK_CAT" "Risk[owner_id]=$ANALYST_ID" "Risk[date_identified]=$TODAY" \
    "Risk[inherent_likelihood]=4" "Risk[inherent_impact]=4" "Risk[residual_likelihood]=2" "Risk[residual_impact]=3" "Risk[treatment]=mitigate" \
    "Risk[deviceIds]=" "Risk[deviceIds][]=$V1" "Risk[incidentIds]=" "Risk[decisionIds]="
RID=$(last_id risk)
check analyst "/risks/$RID" 200
grep -q 'RSK-00' "$BODY" && ok || bad "new risk has no RSK reference"
# Validation: residual > inherent must be rejected (200 with error)
post analyst "/risks/$RID/update" "/risks/$RID/update" 200 \
    "Risk[title]=Smoke test risk" "Risk[status]=assessed" "Risk[category_id]=$RISK_CAT" "Risk[owner_id]=$ANALYST_ID" "Risk[date_identified]=$TODAY" \
    "Risk[inherent_likelihood]=2" "Risk[inherent_impact]=2" "Risk[residual_likelihood]=5" "Risk[residual_impact]=5"
grep -q 'cannot exceed the inherent score' "$BODY" && ok || bad "residual>inherent not rejected"
# Valid update -> accepted
post analyst "/risks/$RID/update" "/risks/$RID/update" 302 \
    "Risk[title]=Smoke test risk (updated)" "Risk[status]=accepted" "Risk[category_id]=$RISK_CAT" "Risk[owner_id]=$ANALYST_ID" "Risk[date_identified]=$TODAY" \
    "Risk[inherent_likelihood]=4" "Risk[inherent_impact]=4" "Risk[residual_likelihood]=1" "Risk[residual_impact]=3" "Risk[treatment]=accept" \
    "Risk[acceptance_justification]=Smoke test acceptance" "Risk[deviceIds]="
check analyst "/risks/$RID" 200
grep -q 'Smoke test acceptance' "$BODY" && ok || bad "risk update not visible"
grep -q 'gw-diff' "$BODY" && ok || bad "risk history diff missing"

# Decision create/update (analyst)
post analyst /decisions/create /decisions/create 302 \
    "Decision[title]=Smoke test decision" "Decision[status]=proposed" "Decision[decision_date]=$TODAY" "Decision[category_id]=$DEC_CAT" \
    "Decision[decision_makers]=CISO" "Decision[decision_text]=Do the thing" "Decision[riskIds]=" "Decision[riskIds][]=$RID" "Decision[incidentIds]=" \
    "Decision[mitigationIds]=" "Decision[mitigationIds][]=$MITIG"
DID=$(last_id decision)
check analyst "/decisions/$DID" 200
grep -q 'M1017' "$BODY" && ok || bad "decision ATT&CK mitigation not shown"
post analyst "/decisions/$DID/update" "/decisions/$DID/update" 302 \
    "Decision[title]=Smoke test decision" "Decision[status]=superseded" "Decision[superseded_by_id]=$D1" "Decision[decision_date]=$TODAY" "Decision[category_id]=$DEC_CAT" \
    "Decision[decision_makers]=CISO" "Decision[decision_text]=Do the thing"
check analyst "/decisions/$DID" 200
grep -q 'Superseded by' "$BODY" && ok || bad "superseded link not shown"

# Incident create + timeline + status change (analyst)
post analyst /incidents/create /incidents/create 302 \
    "Incident[title]=Smoke test incident" "Incident[category_id]=$INC_CAT" "Incident[severity]=high" "Incident[status]=new" \
    "Incident[detected_at]=$NOW" "Incident[personal_data_involved]=1" "Incident[regulator_notification_required]=1" \
    "Incident[impact_confidentiality]=1" "Incident[impact_integrity]=0" "Incident[impact_availability]=1" \
    "Incident[deviceIds]=" "Incident[riskIds]=" "Incident[techniqueIds]=" "Incident[techniqueIds][]=$TECH"
IID=$(last_id incident)
check analyst "/incidents/$IID" 200
grep -q 'T1566.001' "$BODY" && grep -q 'tab-attack' "$BODY" && ok || bad "incident ATT&CK technique not shown"
grep -q 'data-gw-countdown' "$BODY" && ok || bad "incident countdown missing"
grep -q 'data-value="confidentiality"' "$BODY" && grep -q 'data-value="availability"' "$BODY" && ! grep -q 'data-value="integrity"' "$BODY" && ok || bad "incident CIA impact not shown"
check analyst "/incidents?impact=availability" 200
grep -q 'Smoke test incident' "$BODY" && ok || bad "incident CIA filter missing match"
post analyst "/incidents/$IID" "/incidents/$IID/add-entry" 302 \
    "IncidentTimeline[entry_type]=action" "IncidentTimeline[entry_at]=$NOW" "IncidentTimeline[body]=Smoke test timeline entry"
post analyst "/incidents/$IID/update" "/incidents/$IID/update" 302 \
    "Incident[title]=Smoke test incident" "Incident[category_id]=$INC_CAT" "Incident[severity]=high" "Incident[status]=contained" \
    "Incident[detected_at]=$NOW" "Incident[personal_data_involved]=1" "Incident[regulator_notification_required]=1"
check analyst "/incidents/$IID" 200
grep -q 'Smoke test timeline entry' "$BODY" && ok || bad "timeline entry missing"
grep -q 'Status changed from New to Contained' "$BODY" && ok || bad "automatic status timeline entry missing"

# Device: validation, auto tag, override tag, posture, archive/restore/delete
post analyst /devices/create /devices/create 200 \
    "Device[hostname]=smoke-host" "Device[device_type]=laptop" "Device[status]=active" "Device[criticality]=low" \
    "Device[data_classification]=internal" "Device[mac_address]=zz:zz" "Device[ip_addresses]=10.0.0.999"
grep -q 'Enter a MAC address' "$BODY" && grep -q 'Invalid IP address' "$BODY" && ok || bad "device MAC/IP validation not shown"
post analyst /devices/create /devices/create 302 \
    "Device[hostname]=smoke-host" "Device[device_type]=laptop" "Device[status]=active" "Device[criticality]=low" \
    "Device[data_classification]=internal" "Device[mac_address]=aa-bb-cc-dd-ee-ff" "Device[ip_addresses]=10.0.0.9, fe80::1" \
    $(checks pass) "Device[last_patched_at]=$TODAY"
VID=$(last_id device)
check analyst "/devices/$VID" 200
grep -q 'AA:BB:CC:DD:EE:FF' "$BODY" && grep -q 'DEV-00' "$BODY" && ok || bad "device normalisation / auto asset tag failed"
grep -q 'data-value="compliant"' "$BODY" && ok || bad "device not compliant"
post analyst "/devices/$VID/update" "/devices/$VID/update" 302 \
    "Device[asset_tag]=LAPTOP-SMOKE" "Device[hostname]=smoke-host" "Device[device_type]=laptop" "Device[status]=active" "Device[criticality]=medium" \
    "Device[data_classification]=internal" "Device[checks][${CHECKS[0]}]=fail"
check analyst "/devices/$VID" 200
grep -q 'LAPTOP-SMOKE' "$BODY" && grep -q 'Non-compliant' "$BODY" && ok || bad "device asset tag override / posture change failed"
grep -q 'Device checks' "$BODY" && ok || bad "device check change missing from history"
post analyst /devices/create /devices/create 200 \
    "Device[device_type]=mobile" "Device[ownership]=byod" "Device[status]=active" "Device[criticality]=low" "Device[data_classification]=internal"
grep -q 'Enter a device name, a hostname, or both' "$BODY" && ok || bad "device without name or hostname accepted"
post analyst /devices/create /devices/create 302 \
    "Device[name]=Smoke's iPhone" "Device[device_type]=mobile" "Device[ownership]=byod" "Device[status]=active" "Device[criticality]=low" "Device[data_classification]=internal"
PID=$(last_id device)
check analyst "/devices/$PID" 200
grep -q 'Smoke&#039;s iPhone' "$BODY" && grep -q 'Personal (BYOD)' "$BODY" && ok || bad "BYOD device without hostname not shown"
check analyst "/devices?filter=byod" 200
grep -q 'Smoke&#039;s iPhone' "$BODY" && ok || bad "BYOD device missing from BYOD filter"
post analyst "/devices/$PID" "/devices/$PID/archive" 302
post admin "/devices/$PID" "/devices/$PID/delete" 302
post analyst "/devices/$VID" "/devices/$VID/archive" 302
check analyst "/devices?filter=archived" 200
grep -q 'LAPTOP-SMOKE' "$BODY" && ok || bad "archived device not in archived filter"
# Devices synced from Google Workspace (seeded like `make import-devices`): sync panel, stale flag and filter
GID=$(sql "SELECT id FROM device WHERE source='google' AND archived_at IS NULL AND last_seen_at < NOW() - INTERVAL 40 DAY AND status='active' ORDER BY id LIMIT 1")
check viewer "/devices/$GID" 200
grep -q 'Google Workspace' "$BODY" && grep -q 'data-value="stale"' "$BODY" && grep -q '@example.com' "$BODY" && ok || bad "synced device sync panel / stale flag missing"
check viewer "/devices?filter=stale" 200
grep -q 'data-value="stale"' "$BODY" && ok || bad "stale filter shows no stale devices"
check viewer "/" 200
grep -q 'gw-attention__group--stale-devices' "$BODY" && ok || bad "stale devices missing from Needs attention"
post analyst "/devices/$VID" "/devices/$VID/delete" 403
post analyst "/devices/$VID" "/devices/$VID/restore" 302
post analyst "/devices/$VID" "/devices/$VID/archive" 302
post admin "/devices/$VID" "/devices/$VID/delete" 302
check admin "/devices/$VID" 404

# Control: validation, create with frameworks / ATT&CK / risk link, device check, tests, incident roles, archive/delete
MFA_MIT=$(sql "SELECT id FROM attack_mitigation WHERE external_id='M1032'")
post analyst /controls/create /controls/create 200 "Control[title]=" "Control[status]=planned" "Control[control_type]=preventive" "Control[nature]=technical"
grep -q 'Title cannot be blank' "$BODY" && ok || bad "control without title accepted"
post analyst /controls/create /controls/create 200 "Control[title]=Smoke patch check" "Control[status]=implemented" "Control[control_type]=preventive" "Control[nature]=technical" \
    "Control[device_check]=patch_age" "Control[check_max_age_days]="
grep -q 'Set the maximum patch age' "$BODY" && ok || bad "patch-age check without a maximum accepted"
post analyst /controls/create /controls/create 302 \
    "Control[title]=Smoke test control" "Control[status]=implemented" "Control[control_type]=detective" "Control[nature]=technical" "Control[owner_id]=$ANALYST_ID" \
    "Control[test_frequency_months]=3" "Control[description]=Smoke test description" "Control[device_check]=manual" "Control[deviceTypeList]=" "Control[deviceTypeList][]=laptop" \
    "Control[device_ownership]=all" "Control[requirementIds]=" "Control[requirementIds][]=$REQ_ISO" "Control[requirementIds][]=$REQ_CE" \
    "Control[mitigationIds]=" "Control[mitigationIds][]=$MFA_MIT" "Control[riskIds]=" "Control[riskIds][]=$R1" "Control[decisionIds]="
CID=$(last_id control)
check analyst "/controls/$CID" 200
grep -q 'CTL-00' "$BODY" && grep -q 'A.8.7' "$BODY" && grep -q 'CE-UA-4' "$BODY" && grep -q 'M1032' "$BODY" && grep -q 'tab-devices' "$BODY" && ok || bad "new control details / mappings / device tab missing"
check analyst "/frameworks/requirement/$REQ_CE" 200
grep -q 'Smoke test control' "$BODY" && ok || bad "control missing from its Cyber Essentials requirement"
check analyst "/devices/$V1/update" 200
grep -q "Device\[checks\]\[$CID\]" "$BODY" && ok || bad "new device check missing from the device form"
check analyst "/risks/$R1" 200
grep -q 'Smoke test control' "$BODY" && ok || bad "control missing from linked risk"
post analyst "/controls/$CID" "/controls/$CID/record-test" 200 "ControlTest[tested_on]=$TODAY" "ControlTest[result]="
grep -q 'Result cannot be blank' "$BODY" && ok || bad "test without result accepted"
post analyst "/controls/$CID" "/controls/$CID/record-test" 302 "ControlTest[tested_on]=$TODAY" "ControlTest[result]=ineffective" \
    "ControlTest[tested_by_id]=$ANALYST_ID" "ControlTest[method]=Smoke test sample" "ControlTest[notes]=Smoke findings"
check analyst "/controls/$CID" 200
grep -q 'Smoke findings' "$BODY" && grep -q 'data-value="ineffective"' "$BODY" && ok || bad "recorded test / effectiveness not shown"
check analyst "/risks?filter=control-issues" 200
grep -q 'Smoke test control\|data-value="weak-control"' "$BODY" && ok || bad "risk with an ineffective control not flagged"
TID=$(last_id control_test)
post viewer "/controls/$CID" "/controls/$CID/record-test" 403 "ControlTest[tested_on]=$TODAY" "ControlTest[result]=effective"
post analyst "/controls/$CID" "/controls/$CID/delete-test?test=$TID" 302
check analyst "/controls/$CID" 200
grep -q 'Smoke findings' "$BODY" && bad "deleted test still shown" || ok
post analyst "/incidents/$I1/update" "/incidents/$I1/update" 302 \
    "Incident[title]=$(sql "SELECT title FROM incident WHERE id=$I1")" "Incident[category_id]=$(sql "SELECT category_id FROM incident WHERE id=$I1")" \
    "Incident[severity]=$(sql "SELECT severity FROM incident WHERE id=$I1")" "Incident[status]=$(sql "SELECT status FROM incident WHERE id=$I1")" \
    "Incident[detected_at]=$(sql "SELECT DATE_FORMAT(detected_at, '%Y-%m-%dT%H:%i') FROM incident WHERE id=$I1")" \
    "Incident[failedControlIds]=" "Incident[failedControlIds][]=$CID"
check analyst "/controls/$CID" 200
grep -q "$(sql "SELECT reference FROM incident WHERE id=$I1")" "$BODY" && ok || bad "incident role link not shown on the control"
post analyst "/frameworks/requirement/$REQ_ISO" "/frameworks/requirement/$REQ_ISO" 200 "FrameworkRequirement[applicable]=0" "FrameworkRequirement[justification]="
grep -q 'Say why this requirement does not apply' "$BODY" && ok || bad "exclusion without justification accepted"
post analyst "/frameworks/requirement/$REQ_ISO" "/frameworks/requirement/$REQ_ISO" 302 "FrameworkRequirement[applicable]=0" "FrameworkRequirement[justification]=Smoke test exclusion"
check viewer /frameworks/iso27001 200
grep -q 'Smoke test exclusion' "$BODY" && ok || bad "SoA justification not shown"
post viewer "/frameworks/requirement/$REQ_ISO" "/frameworks/requirement/$REQ_ISO" 403 "FrameworkRequirement[applicable]=1"
post analyst "/frameworks/requirement/$REQ_ISO" "/frameworks/requirement/$REQ_ISO" 302 "FrameworkRequirement[applicable]=1" "FrameworkRequirement[justification]="
post analyst "/controls/$CID" "/controls/$CID/archive" 302
check analyst "/controls/$CID/update" 302
post analyst "/controls/$CID" "/controls/$CID/record-test" 302 "ControlTest[tested_on]=$TODAY" "ControlTest[result]=effective"
[[ "$(sql "SELECT COUNT(*) FROM control_test WHERE control_id=$CID")" == "0" ]] && ok || bad "test recorded on an archived control"
post analyst "/controls/$CID" "/controls/$CID/delete" 403
post admin "/controls/$CID" "/controls/$CID/delete" 302
check admin "/controls/$CID" 404

# Archive the smoke risk/decision/incident (read-only once archived), then hard delete as admin
for ent in risks:$RID decisions:$DID incidents:$IID; do
    path=${ent%%:*}; id=${ent##*:}
    post analyst "/$path/$id" "/$path/$id/archive" 302
    check analyst "/$path/$id/update" 302
    post admin "/$path/$id" "/$path/$id/delete" 302
done

# Threat intel: note, verdict, block from a report (returns to it), duplicate refused, unblock, delete
post analyst /intel/ip/192.0.2.99 /intel/note 302 indicator=192.0.2.99 "IntelNote[body]=Smoke test note"
post analyst /intel/ip/192.0.2.99 /intel/verdict 302 indicator=192.0.2.99 verdict=suspicious
check analyst /intel/ip/192.0.2.99 200
grep -q 'Smoke test note' "$BODY" && grep -q 'Analyst: Suspicious' "$BODY" && ok || bad "intel note / verdict not shown"
post analyst /intel/ip/192.0.2.99 /intel/blocklist/create 302 return=report "BlocklistEntry[value]=192.0.2.99" "BlocklistEntry[severity]=high" \
    "BlocklistEntry[direction]=inbound" "BlocklistEntry[reason]=Smoke test block" "BlocklistEntry[expires_at]=$(date -d '+2 days' +%Y-%m-%dT%H:%M)" "BlocklistEntry[incident_id]=$I1"
BID=$(last_id intel_blocklist)
check analyst /intel/ip/192.0.2.99 200
grep -q 'gw-intel-flagchip--blocked' "$BODY" && ok || bad "blocked indicator not flagged on its report"
post analyst /intel/blocklist/create /intel/blocklist/create 200 "BlocklistEntry[value]=192.0.2.99" "BlocklistEntry[severity]=high" "BlocklistEntry[direction]=both" "BlocklistEntry[reason]=dup"
grep -q 'is already blocked' "$BODY" && ok || bad "duplicate block not rejected"
post analyst /intel/blocklist/create /intel/blocklist/create 200 "BlocklistEntry[value]=10.0.0.0/6" "BlocklistEntry[severity]=high" "BlocklistEntry[direction]=both" "BlocklistEntry[reason]=broad"
grep -q 'cannot be blocked' "$BODY" && ok || bad "over-broad network not rejected"
post analyst "/intel/blocklist/$BID" "/intel/blocklist/$BID/update" 302 "BlocklistEntry[value]=192.0.2.0/28" "BlocklistEntry[severity]=critical" "BlocklistEntry[direction]=both" "BlocklistEntry[reason]=Smoke test block (widened)"
check analyst "/intel/blocklist/$BID" 200
grep -q '192.0.2.0/28' "$BODY" && grep -q 'gw-diff' "$BODY" && ok || bad "blocklist update / history missing"
post analyst "/intel/blocklist/$BID" "/intel/blocklist/$BID/archive" 302
check analyst "/intel/blocklist?filter=unblocked" 200
grep -q '192.0.2.0/28' "$BODY" && ok || bad "unblocked entry not under Unblocked"
post analyst "/intel/blocklist/$BID" "/intel/blocklist/$BID/delete" 403
post admin "/intel/blocklist/$BID" "/intel/blocklist/$BID/delete" 302
check admin "/intel/blocklist/$BID" 404
post viewer /intel/ip/192.0.2.99 /intel/note 403 indicator=192.0.2.99 "IntelNote[body]=Smoke test nope"
post viewer /intel/ip/192.0.2.99 /intel/verdict 403 indicator=192.0.2.99 verdict=benign
post viewer /intel/blocklist /intel/blocklist/create 403 "BlocklistEntry[value]=192.0.2.98"
post viewer /intel/sources /intel/sources/update 403
check viewer /intel/ip/203.0.113.66 200
grep -q 'gw-block-modal' "$BODY" && bad "viewer sees the block dialog" || ok

# Viewer cannot write
post viewer /risks /risks/create 403 "Risk[title]=nope"
post viewer "/incidents/$I1" "/incidents/$I1/add-entry" 403 "IncidentTimeline[body]=nope"

# Admin: lookup + user management
post admin "/admin/lookup/create?type=location" "/admin/lookup/create?type=location" 302 \
    "Lookup[type]=location" "Lookup[name]=Smoke Test Site" "Lookup[sort_order]=999" "Lookup[is_active]=1"
LID=$(last_id lookup)
post admin /admin/lookup "/admin/lookup/$LID/delete" 302
post admin /admin/user/create /admin/user/create 302 \
    "UserForm[full_name]=Smoke User" "UserForm[username]=$SMOKE_USER" "UserForm[email]=smoke.$$@example.com" \
    "UserForm[role]=viewer" "UserForm[status]=10" "UserForm[password]=Smoke!User-12345" "UserForm[must_change_password]=0"
UID_NEW=$(last_id user)
post admin "/admin/user/$UID_NEW/update" "/admin/user/$UID_NEW/update" 302 \
    "UserForm[full_name]=Smoke User" "UserForm[username]=$SMOKE_USER" "UserForm[email]=smoke.$$@example.com" \
    "UserForm[role]=analyst" "UserForm[status]=10" "UserForm[must_change_password]=0"
login smoke "$SMOKE_USER" "Smoke!User-12345"
check smoke /risks/create 200
post admin "/admin/user/$UID_NEW/reset-password" "/admin/user/$UID_NEW/reset-password" 302 \
    "ResetPasswordForm[password]=Reset!Smoke-6789" "ResetPasswordForm[must_change_password]=1"
check smoke / 302   # auth key rotated -> old session no longer valid
login smoke "$SMOKE_USER" "Reset!Smoke-6789"
REDIRECT=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$(jar smoke)" "$BASE/risks")
[[ "$REDIRECT" == *account/password* ]] && ok || bad "reset password did not force a change ($REDIRECT)"
post admin "/admin/user/$UID_NEW" "/admin/user/$UID_NEW/toggle-status" 302
check smoke /account/password 302   # disabled user is signed out on the next request

# Admin: branding settings (display name in the sidebar, notice on the sign-in page; restored afterwards)
post admin /admin/settings /admin/settings 302 \
    "BrandingForm[app_name]=Smoke Console" "BrandingForm[organisation]=Smoke Org" "BrandingForm[tagline]=" \
    "BrandingForm[login_notice]=Smoke notice" "BrandingForm[pdf_classification]=OFFICIAL"
check viewer / 200
grep -q 'gw-brand__name">Smoke Console<' "$BODY" && ok || bad "display name not shown in the sidebar"
reset_jar guest; check guest /login 200
grep -q 'Smoke notice' "$BODY" && ! grep -q 'gw-auth__tagline' "$BODY" && ok || bad "sign-in page ignores the branding settings"
check guest /branding/logo 404
post admin /admin/settings /admin/settings 302 \
    "BrandingForm[app_name]=" "BrandingForm[organisation]=" "BrandingForm[tagline]=Security operations console" \
    "BrandingForm[login_notice]=Restricted system · all access is monitored and audited" "BrandingForm[pdf_classification]=Confidential"

# Admin: compliance frameworks (Cyber Essentials off and SOC 2 Availability in scope; restored afterwards)
post admin /admin/settings /admin/settings 302 \
    "ComplianceForm[frameworks][]=iso27001" "ComplianceForm[frameworks][]=soc2" "ComplianceForm[soc2_categories][]=availability"
check viewer / 200
! grep -q 'href="/frameworks/cyber-essentials"' "$BODY" && grep -q 'href="/frameworks/soc2"' "$BODY" && ok || bad "sidebar ignores the compliance framework switches"
check viewer /frameworks/cyber-essentials 404
check viewer /frameworks/nist-csf 404
check viewer /frameworks/ncsc-caf 404
check viewer /frameworks/requirement/$REQ_SOC2_A 200
post admin /admin/settings /admin/settings 302 \
    "ComplianceForm[frameworks][]=iso27001" "ComplianceForm[frameworks][]=cyber_essentials" "ComplianceForm[frameworks][]=soc2" \
    "ComplianceForm[frameworks][]=nist_csf" "ComplianceForm[frameworks][]=ncsc_caf" "ComplianceForm[soc2_categories]="
check viewer /frameworks/cyber-essentials 200
check viewer /frameworks/nist-csf 200
check viewer /frameworks/ncsc-caf 200

# Login lockout: 5 bad passwords, then the right one is refused
for i in 1 2 3 4 5; do
    reset_jar lock; t=$(csrf lock /login)
    req lock POST /login "_csrf=$t" "LoginForm[username]=viewer" "LoginForm[password]=wrong-$i"
done
reset_jar lock; t=$(csrf lock /login)
req lock POST /login "_csrf=$t" "LoginForm[username]=viewer" "LoginForm[password]=$DEMO_PASSWORD"
[[ "$CODE" == "200" ]] && grep -q 'Too many failed sign-in attempts' "$BODY" && ok || bad "lockout not enforced ($CODE)"
post admin "/admin/user/$VIEWER_ID" "/admin/user/$VIEWER_ID/unlock" 302
login viewer viewer "$DEMO_PASSWORD"

# CSRF: POST without a token must be rejected
req analyst POST /risks/create "Risk[title]=no csrf"
[[ "$CODE" == "400" ]] && ok || bad "POST without CSRF token -> $CODE (expected 400)"

# ---------------------------------------------------------------- log check (temporary users are removed by the EXIT trap)

LOG_ERRORS=$(docker compose exec -T php sh -c "test -f runtime/logs/app.log && tail -n +$((LOG_START + 1)) runtime/logs/app.log | grep -E '\[(error|warning)\]' | grep -v HttpException | grep -v 'Invalid session auth key' | tail -5")
if [[ -n "$LOG_ERRORS" ]]; then bad "errors in runtime/logs/app.log:"; echo "$LOG_ERRORS"; else ok; fi

echo "== $PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
