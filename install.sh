#!/usr/bin/env bash
#
# Post-clone install script for occweb.
#
# Usage (from nextcloud/apps/):
#   git clone https://github.com/dillardblom/occweb.git
#   bash occweb/install.sh [nextcloud-root] [web-user]
#
# Defaults: nextcloud-root=/var/www/nextcloud, web-user=www-data
#
# What it does:
#   1. Removes dev/CI-only files that aren't needed to run the app
#      (tests, CI config, PHPUnit config, composer files, Makefile).
#   2. chown -R's the app directory to the web server user.
#   3. Enables the app via occ.
#
# Note: this deletes files that are tracked in git. If you plan to keep
# updating this install with `git pull`, be aware that a future upstream
# change to one of the removed files could make a plain `git pull` refuse
# to merge (it will tell you so, and you can `git checkout -- <file>` to
# recover it, or re-clone instead of pulling).

set -euo pipefail

NEXTCLOUD_ROOT="${1:-/var/www/nextcloud}"
WEB_USER="${2:-www-data}"

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_NAME="$(basename "$APP_DIR")"

echo "==> App directory:   $APP_DIR"
echo "==> App name:        $APP_NAME"
echo "==> Nextcloud root:  $NEXTCLOUD_ROOT"
echo "==> Web server user: $WEB_USER"
echo

if [ ! -f "$NEXTCLOUD_ROOT/occ" ]; then
  echo "error: $NEXTCLOUD_ROOT/occ not found — pass the correct Nextcloud root as the first argument." >&2
  exit 1
fi

echo "==> Removing dev/CI-only files not needed at runtime"
rm -rf \
  "$APP_DIR/tests" \
  "$APP_DIR/.travis.yml" \
  "$APP_DIR/phpunit.xml" \
  "$APP_DIR/phpunit.integration.xml" \
  "$APP_DIR/composer.json" \
  "$APP_DIR/composer.lock" \
  "$APP_DIR/Makefile"

echo "==> Setting ownership to $WEB_USER:$WEB_USER"
sudo chown -R "$WEB_USER:$WEB_USER" "$APP_DIR"

echo "==> Enabling $APP_NAME"
sudo -u "$WEB_USER" php "$NEXTCLOUD_ROOT/occ" app:enable "$APP_NAME"

echo
echo "==> Done. $APP_NAME is installed and enabled."
