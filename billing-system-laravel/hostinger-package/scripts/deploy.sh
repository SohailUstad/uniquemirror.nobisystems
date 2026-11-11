#!/usr/bin/env bash
set -euo pipefail

# Run from /home/<account>/domains/<domain>/billing-system-laravel after
# uploading the Laravel release. Refuses to operate from public_html.
APP_ROOT="$(pwd -P)"
APP_NAME_EXPECTED="billing-system-laravel"
PUBLIC_ROOT="$(dirname "$APP_ROOT")/public_html"

if [[ "$(basename "$APP_ROOT")" != "$APP_NAME_EXPECTED" || ! -f "$APP_ROOT/artisan" ]]; then
  echo "Run this script from the private billing-system-laravel app root." >&2
  exit 2
fi
if [[ ! -d "$PUBLIC_ROOT" ]]; then
  echo "Expected sibling public_html directory: $PUBLIC_ROOT" >&2
  exit 2
fi
if [[ ! -f "$APP_ROOT/.env" ]]; then
  echo "Missing private .env. Copy .env.hostinger.example to .env and configure it first." >&2
  exit 2
fi
if grep -Eq '^(DB_DATABASE|DB_USERNAME|DB_PASSWORD|LEGACY_JWT_SECRET)=CHANGE_ME$' "$APP_ROOT/.env"; then
  echo "Replace all database and JWT placeholders in the private .env before deploying." >&2
  exit 2
fi
if ! grep -Eq '^APP_KEY[=]base64:[A-Za-z0-9+/]{43}=$' "$APP_ROOT/.env"; then
  echo "Set a unique base64 APP_KEY in the private .env before deploying." >&2
  exit 2
fi

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer2}"
"$PHP_BIN" -v | head -n 1
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || { echo "PHP 8.2+ is required." >&2; exit 2; }

mkdir -p "$APP_ROOT/storage/framework/cache/data" "$APP_ROOT/storage/framework/sessions" \
  "$APP_ROOT/storage/framework/views" "$APP_ROOT/storage/logs" "$APP_ROOT/bootstrap/cache"
chmod -R u+rwX "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"

# Install exactly the lockfile dependencies. Production installs omit dev packages.
"$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# Copy only Laravel's public files; preserve unrelated files already in public_html.
# The package entry point and rewrite rules replace Laravel's originals below.
cp -a "$APP_ROOT/public/." "$PUBLIC_ROOT/"
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
install -m 0644 "$PACKAGE_ROOT/public/index.php" "$PUBLIC_ROOT/index.php"
install -m 0644 "$PACKAGE_ROOT/public/.htaccess" "$PUBLIC_ROOT/.htaccess"

# Keep caches uncompiled: Hostinger's Git sync does not run this script on each
# push, so cached routes/config would otherwise continue serving old code.
"$PHP_BIN" artisan optimize:clear

cat <<'DONE'
Release files synced and Laravel caches cleared. Review permissions and check HTTPS/API/files endpoints.
Database migrations are deliberately manual: back up the DB, inspect migration
compatibility with Hostinger MariaDB, then run `php artisan migrate --force`.
DONE
