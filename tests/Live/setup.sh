#!/usr/bin/env bash
# Installs the package from a local checkout into an existing (fresh) Laravel app and copies the
# live fixtures into it. Usage: tests/Live/setup.sh <package-dir> <app-dir>
set -euo pipefail

PACKAGE_DIR="$(cd "${1:?package dir missing}" && pwd)"
APP_DIR="$(cd "${2:?app dir missing}" && pwd)"
COMPOSER="${COMPOSER_BIN:-composer}"
LIVE_DIR="$PACKAGE_DIR/tests/Live"

cd "$APP_DIR"

"$COMPOSER" config repositories.bfsg "{\"type\":\"path\",\"url\":\"$PACKAGE_DIR\",\"options\":{\"symlink\":false}}"
# No -W: the package must install next to whatever the fresh app already locked (e.g. Guzzle 8).
"$COMPOSER" require "itsjustvita/laravel-bfsg:*@dev" barryvdh/laravel-dompdf laravel/mcp --no-interaction

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

php artisan config:clear >/dev/null
echo "setup: itsjustvita/laravel-bfsg installed into $APP_DIR"
