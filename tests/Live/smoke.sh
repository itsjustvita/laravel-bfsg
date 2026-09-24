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

# 1b. Every command line in README.md, UPGRADE.md and SPA-TESTING.md names a command and options the installed package has
php "$LIVE_DIR/doc-commands.php" "$LIVE_DIR/../.." >"$WORK/doc-commands.out" 2>&1 || { cat "$WORK/doc-commands.out"; fail "the docs name commands or options the installed package does not have"; }
pass "docs: $(tail -1 "$WORK/doc-commands.out")"

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

php artisan db:table bfsg_reports --json >"$WORK/reports-table.json" 2>&1 || { cat "$WORK/reports-table.json"; fail "db:table bfsg_reports"; }
grep -q '"url_hash"' "$WORK/reports-table.json" || { cat "$WORK/reports-table.json"; fail "bfsg_reports has no url_hash column"; }
[ "$(php artisan migrate:status | grep -c 'widen_url_of_bfsg_reports')" = "1" ] || fail "the url migration is not listed exactly once"
[ "$(php artisan migrate:status | grep -c 'upgrade_bfsg_tables')" = "1" ] || fail "the undated upgrade migration is not listed exactly once"
pass "bfsg_reports has url_hash"

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

# 6d2. Typos and ignored combinations are operational errors (2), never the threshold code (1); -q keeps the report
check unknown-option 2 /live/broken --failon=warning
grep -q 'does not exist' "$WORK/unknown-option.err" || { cat "$WORK/unknown-option.out" "$WORK/unknown-option.err"; fail "the unknown option was not named on stderr"; }
check bad-locale 2 /live/broken --locale=../../../tmp/x
check engine-without-browser 2 /live/broken --engine=firefox
check quiet 1 /live/broken --format=json -q
pure_json "$WORK/quiet.out" || fail "-q suppressed the JSON report"
pass "bfsg:check unknown option, invalid locale and ignored combination exit 2; -q keeps the report"

# 6f. --save stores the report; bfsg:history lists it with a whole-number score
check saved 1 /live/broken --save
grep -q 'Stored as report #' "$WORK/saved.err" || { cat "$WORK/saved.err"; fail "bfsg:check --save did not store the report"; }
php artisan bfsg:history >"$WORK/history.out" 2>&1 || { cat "$WORK/history.out"; fail "bfsg:history"; }
grep -E 'live/broken +\| +[0-9]+ +\| +[0-9]+% ' "$WORK/history.out" >/dev/null || { cat "$WORK/history.out"; fail "bfsg:history does not list the saved report with a whole-number score"; }
pass "bfsg:check --save and bfsg:history"

# 6f2. URLs longer than 255 characters are stored whole and found again by the pasted URL (query string included)
LONG_PATH="/live/long/$(printf 'x%.0s' $(seq 1 600))"
check long-url 1 "$LONG_PATH" --save
LONG_URL="$(sed -n 's/^Checking //p' "$WORK/long-url.err" | head -1)"
php artisan bfsg:history --url="$LONG_URL?utm_source=smoke" >"$WORK/long-history.out" 2>&1 || { cat "$WORK/long-history.out"; fail "bfsg:history --url with a long URL"; }
grep -q 'live/long/xxx' "$WORK/long-history.out" || { cat "$WORK/long-history.out" "$WORK/long-url.err"; fail "bfsg:history did not find the report of a 600-character path"; }
pass "bfsg:check --save with a long URL, found by bfsg:history --url"

# 6g. Credentials in the URL (HTTP basic auth) go with the form login too; a second Authorization header is an error
BASIC="http://deploy:s3cret@127.0.0.1:$PORT"
check basic-login 0 "$BASIC/live/basic/dashboard" --auth --email=live@example.com --password=secret --login-url=/live/basic/login
grep -q "Checking $BASE/live/basic/dashboard" "$WORK/basic-login.err" || { cat "$WORK/basic-login.err"; fail "status line of the basic-auth check"; }
if grep -q 's3cret' "$WORK/basic-login.out" "$WORK/basic-login.err"; then fail "the URL password appears in the output"; fi
check basic-missing 2 "$BASE/live/basic/dashboard" --auth --email=live@example.com --password=secret --login-url=/live/basic/login
check basic-bearer 2 "$BASIC/live/basic/dashboard" --bearer=token
grep -q 'both use the Authorization header' "$WORK/basic-bearer.err" || { cat "$WORK/basic-bearer.err"; fail "URL credentials with --bearer were not rejected with the reason"; }
pass "bfsg:check with URL credentials: form login behind basic auth, missing credentials exit 2, --bearer conflict exit 2"

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

# 7b. Middleware terminate(): every analyzed page is stored (clean pages too), the log line carries counts only,
#     and in-process requests of bfsg:check are never analyzed by the middleware
curl -s -o /dev/null "$BASE/live/accessible"
stored=""
for _ in $(seq 1 25); do
    php artisan bfsg:history --url="$BASE/live/broken" >"$WORK/mw-broken.out" 2>&1
    php artisan bfsg:history --url="$BASE/live/accessible" >"$WORK/mw-accessible.out" 2>&1
    if grep -q 'live/broken' "$WORK/mw-broken.out" && grep -q 'live/accessible' "$WORK/mw-accessible.out"; then
        stored=yes
        break
    fi
    sleep 0.2
done
[ -n "$stored" ] || { cat "$WORK/mw-broken.out" "$WORK/mw-accessible.out"; fail "middleware: pages were not stored after the response (terminate)"; }
LOG_RUN="$(tail -c +"$((LOG_OFFSET + 1))" "$LOG" 2>/dev/null)"
echo "$LOG_RUN" | grep -q "BFSG: [0-9]* violations on $BASE/live/broken {\"errors\":" || { echo "$LOG_RUN" | tail -5; fail "middleware: no count-only log line for the broken page"; }
if echo "$LOG_RUN" | grep 'BFSG: ' | grep -q 'images.missing_alt'; then fail "middleware: the log line still carries the violation payload"; fi
if echo "$LOG_RUN" | grep -q 'BFSG: [0-9]* violations on http://localhost'; then fail "middleware: analyzed an in-process request of bfsg:check"; fi
pass "middleware: terminate() stores broken and clean pages, logs counts only, skips in-process checks"

# 7c. Blade component: bare <img> with its attributes once, <figure> only with a caption, no redundant role,
#     and the page passes the package's own analyzers
curl -s "$BASE/live/component" >"$WORK/component.html"
grep -q '<img src="/lake.jpg" alt="Mountain lake at sunrise" class="rounded" width="300" id="bfsg-plain">' "$WORK/component.html" || { grep -n '<img\|<figure' "$WORK/component.html"; fail "component: plain image not rendered as a bare <img>"; }
[ "$(grep -c '<figure' "$WORK/component.html")" = "1" ] || fail "component: expected exactly one <figure> (the captioned image)"
if grep -q 'role="presentation"' "$WORK/component.html"; then fail "component: decorative image carries a redundant role"; fi
check component 0 /live/component --fail-on=notice
pass "Blade component renders clean markup that passes the package's own analyzers"

# 8. MCP server over stdio: snake_case tools with annotations, current version, analyze_url of an app path in-process
cat >"$WORK/mcp.in" <<'JSON'
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"bfsg-live","version":"1"}}}
{"jsonrpc":"2.0","method":"notifications/initialized"}
{"jsonrpc":"2.0","id":2,"method":"tools/list"}
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"analyze_url","arguments":{"url":"/live/broken","locale":"de"}}}
{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"check_contrast","arguments":{"foreground":"#767676","background":"#ffffff"}}}
{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"analyze_url","arguments":{"url":"http://169.254.169.254/latest/meta-data/"}}}
{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"analyze_html","arguments":{"html":"<p>x</p>","locale":"../../../tmp/x"}}}
JSON
php artisan bfsg:mcp-server <"$WORK/mcp.in" >"$WORK/mcp.out" 2>&1 || { cat "$WORK/mcp.out"; fail "bfsg:mcp-server exited non-zero"; }
cat >"$WORK/mcp-check.php" <<'PHP'
<?php
$byId = [];
foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $message = json_decode($line, true);
    if (is_array($message) && isset($message['id'])) {
        $byId[$message['id']] = $message;
    }
}
$fail = function (string $why) { fwrite(STDERR, $why."\n"); exit(1); };
$version = $byId[1]['result']['serverInfo']['version'] ?? '';
if ($version === '' || $version === '2.1.0') { $fail("serverInfo.version is [$version]"); }
$tools = array_column($byId[2]['result']['tools'] ?? [], null, 'name');
foreach (['analyze_html', 'analyze_url', 'check_contrast', 'list_analyzers', 'get_history', 'get_report', 'generate_report'] as $name) {
    isset($tools[$name]) || $fail("tools/list lacks $name");
}
($tools['analyze_html']['annotations']['readOnlyHint'] ?? false) === true || $fail('analyze_html is not annotated read-only');
($tools['analyze_url']['annotations']['openWorldHint'] ?? false) === true || $fail('analyze_url is not annotated open-world');
($byId[3]['result']['isError'] ?? true) === false || $fail('analyze_url failed: '.json_encode($byId[3] ?? null));
$report = json_decode($byId[3]['result']['content'][0]['text'] ?? '', true);
($report['locale'] ?? null) === 'de' || $fail('analyze_url ignored the locale');
in_array('images.missing_alt', array_column($report['violations']['images'] ?? [], 'key'), true) || $fail('analyze_url did not report images.missing_alt');
$contrast = json_decode($byId[4]['result']['content'][0]['text'] ?? '', true);
($contrast['aa_normal'] ?? null) === true || $fail('check_contrast #767676 on white should pass AA');
($byId[5]['result']['isError'] ?? false) === true || $fail('analyze_url fetched the cloud metadata address: '.json_encode($byId[5] ?? null));
($byId[6]['result']['isError'] ?? false) === true || $fail('analyze_html accepted a path as locale: '.json_encode($byId[6] ?? null));
PHP
php "$WORK/mcp-check.php" "$WORK/mcp.out" || { cat "$WORK/mcp.out"; fail "MCP over stdio"; }
pass "MCP over stdio: tools with annotations, analyze_url /live/broken in-process, check_contrast, private address and bad locale refused"

# 9. --browser renders the page's JavaScript and inlines its same-origin stylesheets from the CSSOM. Needs Playwright in
#    the app (CI installs it and sets BFSG_LIVE_BROWSER=required; a local run without it skips this step).
if node -e "require.resolve('playwright')" >/dev/null 2>&1; then
    check spa-plain 0 /live/spa --only=contrast --format=json
    check spa-browser 1 "$BASE/live/spa" --browser --only=contrast --format=json
    php "$LIVE_DIR/violation-keys.php" <"$WORK/spa-browser.out" >"$WORK/spa-browser.keys" || fail "bfsg:check --browser --format=json"
    grep -q ' contrast.insufficient$' "$WORK/spa-browser.keys" || { cat "$WORK/spa-browser.keys" "$WORK/spa-browser.err"; fail "--browser did not measure the JavaScript paragraph against the inlined stylesheet"; }
    check spa-browser-no-css 0 "$BASE/live/spa" --browser --no-inline-css --only=contrast --format=json
    pass "bfsg:check --browser: JavaScript content, stylesheet inlined (contrast found), --no-inline-css honoured"
elif [ "${BFSG_LIVE_BROWSER:-}" = required ]; then
    fail "Playwright is not installed in $APP_DIR (BFSG_LIVE_BROWSER=required)"
else
    echo "skip --browser (Playwright is not installed in $APP_DIR)"
fi

# Only lines written during this run count.
if [ -f "$LOG" ] && tail -c +"$((LOG_OFFSET + 1))" "$LOG" | grep -q '\.ERROR:'; then
    tail -c +"$((LOG_OFFSET + 1))" "$LOG" | grep '\.ERROR:'
    fail "errors in $LOG during the smoke test"
fi
pass "no errors in laravel.log during the run"

echo "live smoke test passed"
