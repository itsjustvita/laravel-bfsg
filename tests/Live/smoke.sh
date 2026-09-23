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

cd "$APP_DIR" || fail "cannot enter $APP_DIR"

LOG=storage/logs/laravel.log
LOG_OFFSET=$( [ -f "$LOG" ] && wc -c <"$LOG" | tr -d ' ' || echo 0 )

# 1. The app boots with the package installed
php artisan about >"$WORK/about.txt" 2>&1 || { cat "$WORK/about.txt"; fail "php artisan about"; }
pass "php artisan about"

php artisan list --raw | grep -q '^bfsg:check' || fail "bfsg:check is not registered"
php artisan list --raw | grep -q '^bfsg:mcp-server' || fail "bfsg:mcp-server is not registered (laravel/mcp installed?)"
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

# 4. Web server
php artisan serve --port="$PORT" >"$WORK/serve.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
    curl -s -o /dev/null "$BASE/live/accessible" && break
    sleep 0.2
done
curl -s -o /dev/null "$BASE/live/accessible" || { cat "$WORK/serve.log"; fail "php artisan serve did not come up on $BASE"; }
pass "php artisan serve on $BASE"

# 5. bfsg:check finds the planted defects on the broken page
php artisan bfsg:check "$BASE/live/broken" --format=json >"$WORK/broken.out" 2>"$WORK/broken.err"
php "$LIVE_DIR/violation-keys.php" <"$WORK/broken.out" >"$WORK/broken.keys" || fail "bfsg:check --format=json on the broken page"
for key in images.missing_alt forms.control_missing_label links.missing_name language.missing_lang page_title.missing_title contrast.insufficient focus.outline_removed keyboard.click_without_keyboard headings.skipped_level; do
    grep -q " $key\$" "$WORK/broken.keys" || { cat "$WORK/broken.keys"; fail "bfsg:check did not report $key on the broken page"; }
done
pass "bfsg:check broken page: $(wc -l <"$WORK/broken.keys" | tr -d ' ') findings incl. all expected keys"

# 6. ... and no errors on the accessible page
php artisan bfsg:check "$BASE/live/accessible" --format=json >"$WORK/accessible.out" 2>"$WORK/accessible.err"
php "$LIVE_DIR/violation-keys.php" <"$WORK/accessible.out" >"$WORK/accessible.keys" || fail "bfsg:check --format=json on the accessible page"
if grep -q '^error ' "$WORK/accessible.keys"; then
    cat "$WORK/accessible.keys"
    fail "bfsg:check reported errors on the accessible page"
fi
pass "bfsg:check accessible page: no errors ($(wc -l <"$WORK/accessible.keys" | tr -d ' ') findings)"

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
