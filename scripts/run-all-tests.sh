#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Run every Greywarden test suite against the TEST database only.
#
#   scripts/run-all-tests.sh              leak check, PHPUnit (unit + functional) and the HTTP smoke test
#   scripts/run-all-tests.sh --no-smoke   PHPUnit only
#   scripts/run-all-tests.sh --coverage   also write coverage (src/runtime/coverage/: html/, clover.xml, coverage.txt)
#
# The live database is never touched: PHPUnit runs with DB_NAME=$DB_TEST_NAME, and
# the smoke test runs against a seeded copy served by a throwaway container
# (gw-verify on 127.0.0.1:$SMOKE_PORT). The test database is dropped afterwards.
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."

SMOKE=1; COVERAGE=0
for arg in "$@"; do
    case "$arg" in
        --no-smoke) SMOKE=0 ;;
        --coverage) COVERAGE=1 ;;
        -h|--help) sed -n '3,11p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $arg (try --help)" >&2; exit 1 ;;
    esac
done

command -v docker >/dev/null || { echo "docker is not installed" >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo "Docker daemon is not running (sudo systemctl start docker)" >&2; exit 1; }
[ -f .env ] || { echo "No .env found - run scripts/up.sh first" >&2; exit 1; }
set -a; source .env; set +a
if [[ "${COMPOSE_FILE:-}" == *docker-compose.prod.yml* ]]; then
    echo "This is a production checkout - run the tests in the development stack." >&2; exit 1
fi

DC="docker compose"
LIVE_DB="${DB_NAME:-greywarden}"
TEST_DB="${DB_TEST_NAME:-greywarden_test}"
SMOKE_PORT="${SMOKE_PORT:-8099}"
VERIFY=gw-verify
ROUTER=runtime/gw-verify-router.php        # inside the php container (src/runtime on the host)

if [ "$TEST_DB" = "$LIVE_DB" ]; then
    echo "DB_TEST_NAME ($TEST_DB) is the live database - refusing to run." >&2; exit 1
fi
for svc in db php; do
    if [ -z "$($DC ps --status running -q "$svc")" ]; then
        echo "The $svc container is not running - start the stack with scripts/up.sh" >&2; exit 1
    fi
done

# Runs inside the db container with its own MARIADB_* env (no secrets on the command line)
db_root() { $DC exec -T db sh -c "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" -e \"$1\""; }
php_test() { $DC exec -T -e DB_NAME="$TEST_DB" -e DB_TEST_NAME="$TEST_DB" php "$@"; }

cleanup() {
    docker rm -f "$VERIFY" >/dev/null 2>&1 || true
    rm -f "src/$ROUTER"
    # Seeded demo data would break the unit tests next time; `make test` recreates the database
    db_root "DROP DATABASE IF EXISTS \\\`$TEST_DB\\\`" >/dev/null 2>&1 || echo "WARNING: could not drop $TEST_DB" >&2
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

declare -a NAMES=() RESULTS=()
record() { NAMES+=("$1"); RESULTS+=("$2"); }
run_step() { # run_step <name> <command...>
    local name=$1; shift
    echo; echo "== $name"
    if "$@"; then record "$name" PASS; else record "$name" FAIL; fi
}

fresh_test_db() {
    db_root "DROP DATABASE IF EXISTS \\\`$TEST_DB\\\`; CREATE DATABASE \\\`$TEST_DB\\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
GRANT ALL PRIVILEGES ON \\\`$TEST_DB\\\`.* TO \\\"\$MARIADB_USER\\\"@\\\"%\\\"; FLUSH PRIVILEGES;"
    php_test php yii migrate --interactive=0 >/dev/null
}

# ---------------------------------------------------------------- Leak check (host only, no containers)
run_step "Leak check tests (scripts/leak-check.sh, .githooks)" ./scripts/tests/leak-check-test.sh
run_step "Leak check of the files in the index" ./scripts/leak-check.sh

# ---------------------------------------------------------------- PHPUnit
echo
echo "== Preparing a fresh test database ($TEST_DB)"
fresh_test_db

if [ "$COVERAGE" = 1 ]; then
    # Coverage needs pcov: a derived image (never the running php container) with pcov added
    PHP_IMAGE=$(docker inspect -f '{{.Config.Image}}' "$($DC ps -q php)")
    COV_IMAGE="${PHP_IMAGE%%:*}-cov:${PHP_IMAGE##*:}"
    if ! docker image inspect "$COV_IMAGE" >/dev/null 2>&1; then
        echo "Building $COV_IMAGE (adds pcov) ..."
        printf 'FROM %s\nUSER root\nRUN install-php-extensions pcov\nUSER app\n' "$PHP_IMAGE" | docker build -q -t "$COV_IMAGE" - >/dev/null
    fi
    NETWORK=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$($DC ps -q db)" | awk '{print $1}')
    phpunit_cov() {
        docker run --rm --network "$NETWORK" --env-file .env -e DB_NAME="$TEST_DB" -e DB_TEST_NAME="$TEST_DB" \
            -v "$PWD/src:/var/www/html" "$COV_IMAGE" sh -c 'rm -rf runtime/coverage && php -d pcov.enabled=1 -d pcov.directory=/var/www/html \
                vendor/bin/phpunit --coverage-html runtime/coverage/html --coverage-clover runtime/coverage/clover.xml \
                --coverage-text=runtime/coverage/coverage.txt --only-summary-for-coverage-text'
    }
    run_step "PHPUnit (unit + functional, with coverage)" phpunit_cov
else
    run_step "PHPUnit unit suite" php_test vendor/bin/phpunit --testsuite unit
    run_step "PHPUnit functional suite" php_test vendor/bin/phpunit --testsuite functional
fi

# ---------------------------------------------------------------- Smoke test (seeded test database)
smoke() {
    # (set -e does not apply inside a function run as an `if` condition, so failures return explicitly)
    # A fresh database: ids the smoke test expects are not shifted by rolled-back PHPUnit inserts
    fresh_test_db || return 1
    php_test php yii seed/demo --reset=1 --interactive=0 >/dev/null || return 1
    # php -S cannot route URLs containing a dot (/attack/T1566.001, /intel/ip/...) without a router
    cat > "src/$ROUTER" <<'PHP'
<?php
// Throwaway router for scripts/run-all-tests.sh (php -S); removed when the run ends
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file('/var/www/html/web' . $path)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/web/index.php';
require '/var/www/html/web/index.php';
PHP
    docker rm -f "$VERIFY" >/dev/null 2>&1 || true
    # No credentials for external services, and YII_ENV=test (no background feed downloads, no debug module)
    $DC run -d --rm --no-deps --name "$VERIFY" -p "127.0.0.1:$SMOKE_PORT:$SMOKE_PORT" \
        -e DB_NAME="$TEST_DB" -e YII_ENV=test -e CLOUDFLARE_API_TOKEN= -e CLOUDFLARE_ZONE_ID= \
        -e ABUSEIPDB_API_KEY= -e VIRUSTOTAL_API_KEY= -e GREYNOISE_API_KEY= -e OTX_API_KEY= -e ABUSECH_AUTH_KEY= -e IPINFO_TOKEN= \
        php php -S "0.0.0.0:$SMOKE_PORT" -t web "/var/www/html/$ROUTER" >/dev/null || return 1
    local url="http://127.0.0.1:$SMOKE_PORT"
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "$url/login" && break
        sleep 0.2
    done
    SMOKE_DB_NAME="$TEST_DB" SMOKE_BASE_URL="$url" ./scripts/smoke-test.sh
}
if [ "$SMOKE" = 1 ]; then
    run_step "HTTP smoke test (seeded $TEST_DB on 127.0.0.1:$SMOKE_PORT)" smoke
fi

# ---------------------------------------------------------------- Summary
if [ "$COVERAGE" = 1 ] && [ -f src/runtime/coverage/clover.xml ]; then
    echo; echo "== Coverage (lines) - HTML report: src/runtime/coverage/html/index.html"
    php_test php tests/tools/coverage-summary.php runtime/coverage/clover.xml || true
fi

echo; echo "== Summary"
FAILED=0
for i in "${!NAMES[@]}"; do
    printf '  %-4s  %s\n' "${RESULTS[$i]}" "${NAMES[$i]}"
    [ "${RESULTS[$i]}" = PASS ] || FAILED=1
done
if [ "$FAILED" = 0 ]; then echo "All test suites passed."; else echo "Some test suites FAILED."; fi
exit "$FAILED"
