#!/usr/bin/env bash
# Live smoke test of laravel-bfsg inside a real Laravel app (prepared by tests/Live/setup.sh).
# Usage: tests/Live/smoke.sh <app-dir>. Exits non-zero with a message on the first failure.
# Not part of phpunit: it needs a full application, a web server and a network port.
# No `set -e` on purpose: several steps expect non-zero exit codes (bfsg:check exits 1 or 2 by design),
# so every step checks its own result and calls fail() with a message instead.
set -uo pipefail

LIVE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${1:?app dir missing}" && pwd)"
PORT="${BFSG_LIVE_PORT:-8899}"
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
SERVER_PID=""

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null
        pkill -f "artisan serve --port=$PORT" 2>/dev/null
        pkill -f "127.0.0.1:$PORT" 2>/dev/null
    fi
    rm -rf "$WORK"
}
trap cleanup EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

pass() {
    echo "ok   $*"
}

# check <name> <expected exit code> <bfsg:check arguments...>: stdout -> $WORK/<name>.out, stderr -> $WORK/<name>.err
check() {
    local name="$1" expected="$2"
    shift 2
    php artisan bfsg:check "$@" >"$WORK/$name.out" 2>"$WORK/$name.err"
    local code=$?
    [ "$code" = "$expected" ] || { cat "$WORK/$name.out" "$WORK/$name.err"; fail "bfsg:check $* exited $code, expected $expected"; }
}

# pure_json <file>: the whole file is one JSON document (no status lines around it)
pure_json() {
    php -r 'json_decode(stream_get_contents(STDIN), flags: JSON_THROW_ON_ERROR);' <"$1" 2>/dev/null
}

cd "$APP_DIR" || fail "cannot enter $APP_DIR"

LOG=storage/logs/laravel.log
LOG_OFFSET=$( [ -f "$LOG" ] && wc -c <"$LOG" | tr -d ' ' || echo 0 )

# 1. The app boots with the package installed
php artisan about >"$WORK/about.txt" 2>&1 || { cat "$WORK/about.txt"; fail "php artisan about"; }
pass "php artisan about"

php artisan list --raw | grep -q '^bfsg:check' || fail "bfsg:check is not registered"
php artisan list --raw | grep -q '^bfsg:mcp-server' || fail "bfsg:mcp-server is not registered (laravel/mcp installed?)"
if php artisan list --raw | grep -q '^bfsg:analyze'; then fail "bfsg:analyze is still registered (replaced by bfsg:check --browser)"; fi
pass "bfsg commands registered"

# 2. Every publish tag works
for tag in bfsg-config bfsg-lang bfsg-views bfsg-migrations; do
    php artisan vendor:publish --tag="$tag" --force >"$WORK/publish.txt" 2>&1 || { cat "$WORK/publish.txt"; fail "vendor:publish --tag=$tag"; }
done
[ -f config/bfsg.php ] || fail "config/bfsg.php not published"
[ -d lang/vendor/bfsg ] || fail "lang/vendor/bfsg not published"
[ -d resources/views/vendor/bfsg ] || fail "resources/views/vendor/bfsg not published"
ls database/migrations | grep -q 'bfsg' || fail "bfsg migration not published"
php artisan config:clear >/dev/null
pass "vendor:publish (config, lang, views, migrations)"

# 3. Migrations run (twice: the published copy must not clash with the package copy)
php artisan migrate --force >"$WORK/migrate.txt" 2>&1 || { cat "$WORK/migrate.txt"; fail "migrate"; }
php artisan migrate --force >"$WORK/migrate.txt" 2>&1 || { cat "$WORK/migrate.txt"; fail "second migrate"; }
pass "migrate --force"

php artisan db:table bfsg_violations --json >"$WORK/violations-table.json" 2>&1 || { cat "$WORK/violations-table.json"; fail "db:table bfsg_violations"; }
for column in key fingerprint context; do
    grep -q "\"$column\"" "$WORK/violations-table.json" || { cat "$WORK/violations-table.json"; fail "bfsg_violations has no $column column"; }
done
[ "$(php artisan migrate:status | grep -c 'add_context_and_fingerprint_to_bfsg_violations')" = "1" ] || fail "the upgrade migration is listed more than once (published copy shadows the package copy?)"
pass "bfsg_violations has key, fingerprint and context"

# 4. Web server
php artisan serve --port="$PORT" >"$WORK/serve.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
    curl -s -o /dev/null "$BASE/live/accessible" && break
    sleep 0.2
done
curl -s -o /dev/null "$BASE/live/accessible" || { cat "$WORK/serve.log"; fail "php artisan serve did not come up on $BASE"; }
pass "php artisan serve on $BASE"

# 5. bfsg:check finds the planted defects on the broken page; stdout is pure JSON; errors exit 1
check broken 1 "$BASE/live/broken" --format=json
pure_json "$WORK/broken.out" || { head -c 300 "$WORK/broken.out"; fail "stdout of bfsg:check --format=json is not pure JSON"; }
php "$LIVE_DIR/violation-keys.php" <"$WORK/broken.out" >"$WORK/broken.keys" || fail "bfsg:check --format=json on the broken page"
for key in images.missing_alt forms.control_missing_label links.missing_name language.missing_lang page_title.missing_title contrast.insufficient focus.outline_removed keyboard.click_without_keyboard headings.skipped_level; do
    grep -q " $key\$" "$WORK/broken.keys" || { cat "$WORK/broken.keys"; fail "bfsg:check did not report $key on the broken page"; }
done
grep -q "Checking $BASE/live/broken" "$WORK/broken.err" || { cat "$WORK/broken.err"; fail "status lines are not on stderr"; }
pass "bfsg:check broken page: $(wc -l <"$WORK/broken.keys" | tr -d ' ') findings incl. all expected keys, pure JSON on stdout, exit 1"

# 6. ... and no errors on the accessible page (exit 0)
check accessible 0 "$BASE/live/accessible" --format=json
php "$LIVE_DIR/violation-keys.php" <"$WORK/accessible.out" >"$WORK/accessible.keys" || fail "bfsg:check --format=json on the accessible page"
if grep -q '^error ' "$WORK/accessible.keys"; then
    cat "$WORK/accessible.keys"
    fail "bfsg:check reported errors on the accessible page"
fi
pass "bfsg:check accessible page: no errors ($(wc -l <"$WORK/accessible.keys" | tr -d ' ') findings), exit 0"

# 6b. A path of this application is fetched in-process through the HTTP kernel (no web server, no .test hack)
check inprocess 1 /live/broken --format=json
php "$LIVE_DIR/violation-keys.php" <"$WORK/inprocess.out" >"$WORK/inprocess.keys" || fail "bfsg:check --format=json on the path /live/broken"
for key in images.missing_alt language.missing_lang page_title.missing_title; do
    grep -q " $key\$" "$WORK/inprocess.keys" || { cat "$WORK/inprocess.keys" "$WORK/inprocess.err"; fail "in-process bfsg:check /live/broken did not report $key"; }
done
pass "bfsg:check /live/broken in-process: $(wc -l <"$WORK/inprocess.keys" | tr -d ' ') findings"

# 6c. Non-HTML answers are an operational error (exit 2), never a pass
check json-route 2 "$BASE/live/json"
grep -q 'not an HTML page' "$WORK/json-route.err" || { cat "$WORK/json-route.err"; fail "bfsg:check did not explain why the JSON response was rejected"; }
pass "bfsg:check rejects a JSON response with exit 2"

# 6d. Options: --fail-on, unknown analyzers, markdown on stdout, --output
check fail-on-none 0 /live/broken --fail-on=none
check unknown-analyzer 2 /live/broken --only=nope
check markdown 1 /live/broken --format=markdown
[ "$(head -c 2 "$WORK/markdown.out")" = '# ' ] || { head -5 "$WORK/markdown.out"; fail "--format=markdown did not print the Markdown report"; }
check output 1 /live/broken --format=json --output="$WORK/report.json"
[ ! -s "$WORK/output.out" ] || { cat "$WORK/output.out"; fail "--output still printed to stdout"; }
pure_json "$WORK/report.json" || fail "--output did not write the JSON report"
grep -q "$WORK/report.json" "$WORK/output.err" || fail "--output did not name the file on stderr"
pass "bfsg:check --fail-on=none, --only validation, --format=markdown, --output"

# 6e. HTML report: written to a file, localized, current version, and it passes the package's own analyzers
check html-report 1 /live/broken --format=html
REPORT="$(grep -o '/[^ ]*report_[0-9a-f_-]*\.html' "$WORK/html-report.err" | head -1)"
{ [ -n "$REPORT" ] && [ -f "$REPORT" ]; } || { cat "$WORK/html-report.err"; fail "bfsg:check --format=html did not write a report file"; }
grep -q '<html lang="en">' "$REPORT" || fail "the HTML report does not declare lang=\"en\""
if grep -q 'v1\.5\.0\|2\.1\.0' "$REPORT"; then fail "the HTML report shows a stale package version"; fi
cp "$REPORT" public/bfsg-live-report.html
check report-check 0 "$BASE/bfsg-live-report.html" --format=json --fail-on=notice
rm -f public/bfsg-live-report.html
pass "HTML report: lang, version, passes its own analyzers"

# 7. Middleware: header on the HTML page, everything else untouched
header() { # url header-name -> value (empty when absent)
    curl -s -D - -o /dev/null "$1" | tr -d '\r' | awk -v h="$(echo "$2" | tr 'A-Z' 'a-z')" -F': ' 'tolower($1)==h {print $2}'
}

[ -n "$(header "$BASE/live/broken" X-BFSG-Violations)" ] || fail "middleware: X-BFSG-Violations missing on the broken HTML page"
[ -z "$(header "$BASE/live/json" X-BFSG-Violations)" ] || fail "middleware: JSON response carries X-BFSG-Violations"
[ -z "$(header "$BASE/live/download" X-BFSG-Violations)" ] || fail "middleware: download carries X-BFSG-Violations"
[ -z "$(header "$BASE/live/redirect" X-BFSG-Violations)" ] || fail "middleware: redirect carries X-BFSG-Violations"

[ "$(curl -s "$BASE/live/json")" = '{"ok":true,"items":[1,2,3]}' ] || fail "middleware: JSON body changed"
[ "$(curl -s "$BASE/live/download")" = 'BFSG-LIVE-DOWNLOAD' ] || fail "middleware: download body changed"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/live/redirect")" = '302' ] || fail "middleware: redirect status changed"
[ "$(header "$BASE/live/redirect" Location)" = "$BASE/live/accessible" ] || fail "middleware: redirect location changed"
pass "middleware: header on HTML only; JSON, download and redirect untouched"

# 8. MCP server over stdio
cat >"$WORK/mcp.in" <<'JSON'
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"bfsg-live","version":"1"}}}
{"jsonrpc":"2.0","method":"notifications/initialized"}
{"jsonrpc":"2.0","id":2,"method":"tools/list"}
JSON
php artisan bfsg:mcp-server <"$WORK/mcp.in" >"$WORK/mcp.out" 2>&1 || { cat "$WORK/mcp.out"; fail "bfsg:mcp-server exited non-zero"; }
grep -q '"analyze_html"' "$WORK/mcp.out" || { cat "$WORK/mcp.out"; fail "MCP tools/list does not list analyze_html"; }
pass "MCP tools/list over stdio lists analyze_html"

# Only lines written during this run count.
if [ -f "$LOG" ] && tail -c +"$((LOG_OFFSET + 1))" "$LOG" | grep -q '\.ERROR:'; then
    tail -c +"$((LOG_OFFSET + 1))" "$LOG" | grep '\.ERROR:'
    fail "errors in $LOG during the smoke test"
fi
pass "no errors in laravel.log during the run"

echo "live smoke test passed"
