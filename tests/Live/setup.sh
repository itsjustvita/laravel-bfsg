#!/usr/bin/env bash
# Installs the package from a local checkout into an existing (fresh) Laravel app and copies the
# live fixtures into it. Usage: tests/Live/setup.sh <package-dir> <app-dir>
# With BFSG_REQUIRE set (e.g. BFSG_REQUIRE='itsjustvita/laravel-bfsg:^3.0') the package comes from Packagist instead
# of the checkout (post-release check); the fixtures still come from <package-dir>.
set -euo pipefail

PACKAGE_DIR="$(cd "${1:?package dir missing}" && pwd)"
APP_DIR="$(cd "${2:?app dir missing}" && pwd)"
COMPOSER="${COMPOSER_BIN:-composer}"
LIVE_DIR="$PACKAGE_DIR/tests/Live"

cd "$APP_DIR"

if [ -n "${BFSG_REQUIRE:-}" ]; then
    # No -W here either: a release must install next to what a fresh app locks.
    "$COMPOSER" require "$BFSG_REQUIRE" barryvdh/laravel-dompdf laravel/mcp --no-interaction
else
    "$COMPOSER" config repositories.bfsg "{\"type\":\"path\",\"url\":\"$PACKAGE_DIR\",\"options\":{\"symlink\":false}}"
    # No -W: the package must install next to whatever the fresh app already locked (e.g. Guzzle 8).
    "$COMPOSER" require "itsjustvita/laravel-bfsg:*@dev" barryvdh/laravel-dompdf laravel/mcp --no-interaction
fi

cp -R "$LIVE_DIR/app/." "$APP_DIR/"

set_env() {
    if grep -q "^$1=" .env; then
        sed -i.bak "s#^$1=.*#$1=$2#" .env && rm -f .env.bak
    else
        printf '%s=%s\n' "$1" "$2" >> .env
    fi
}

set_env APP_DEBUG true
set_env BFSG_MIDDLEWARE_ENABLED true
set_env BFSG_SAVE_TO_DB true

php artisan config:clear >/dev/null
echo "setup: itsjustvita/laravel-bfsg $("$COMPOSER" show itsjustvita/laravel-bfsg --no-ansi 2>/dev/null | awk '/^versions/ {print $NF}') installed into $APP_DIR"
