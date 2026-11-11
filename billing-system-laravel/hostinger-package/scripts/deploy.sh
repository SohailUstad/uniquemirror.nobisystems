#!/usr/bin/env bash
set -euo pipefail

# Hostinger Git deploys the repository at domains/<domain>/public_html.
# The root front controller serves the Laravel app from its subdirectory.
APP_ROOT="$(pwd -P)"
EXPECTED_APP="billing-system-laravel"
PUBLIC_ROOT="$(dirname "$APP_ROOT")"
DOMAIN_ROOT="$(dirname "$PUBLIC_ROOT")"
RUNTIME_ROOT="$DOMAIN_ROOT/billing-system-runtime"

if [[ "$(basename "$APP_ROOT")" != "$EXPECTED_APP" || ! -f "$APP_ROOT/artisan" ]]; then
  echo "Run this script from domains/<domain>/public_html/billing-system-laravel." >&2
  exit 2
fi
if [[ "$(basename "$PUBLIC_ROOT")" != "public_html" || ! -d "$PUBLIC_ROOT" || ! -d "$DOMAIN_ROOT" ]]; then
  echo "Expected the app checkout directly inside the domain's public_html." >&2
  exit 2
fi

if [[ -L "$RUNTIME_ROOT" ]]; then
  echo "Refusing to use a symlink for the external runtime directory." >&2
  exit 2
fi
mkdir -p "$RUNTIME_ROOT/storage/framework/cache/data" \
  "$RUNTIME_ROOT/storage/framework/sessions" \
  "$RUNTIME_ROOT/storage/framework/views" \
  "$RUNTIME_ROOT/storage/logs" \
  "$RUNTIME_ROOT/storage/app/private"
RUNTIME_REAL="$(cd "$RUNTIME_ROOT" && pwd -P)"
if [[ "$RUNTIME_REAL" != "$DOMAIN_ROOT/billing-system-runtime" ]]; then
  echo "Runtime directory resolves outside the expected domain directory." >&2
  exit 2
fi

ENV_FILE="$RUNTIME_ROOT/.env"
APP_ENV="$APP_ROOT/.env"
APP_STORAGE="$APP_ROOT/storage"
APP_CACHE="$APP_ROOT/bootstrap/cache"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE. Copy hostinger-package/.env.hostinger.example there, configure it, and retry." >&2
  exit 2
fi
if [[ -e "$APP_ENV" || -L "$APP_ENV" ]]; then
  echo "Refusing app-root .env; remove it and configure the external runtime .env." >&2
  exit 2
fi
if grep -Eq '^(DB_DATABASE|DB_USERNAME|DB_PASSWORD|LEGACY_JWT_SECRET)=CHANGE_ME$' "$ENV_FILE"; then
  echo "Replace all database and JWT placeholders in the external runtime .env before deploying." >&2
  exit 2
fi
if ! grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$' "$ENV_FILE"; then
  echo "Set a unique base64 APP_KEY in the external runtime .env before deploying." >&2
  exit 2
fi

if [[ -e "$APP_STORAGE" || -L "$APP_STORAGE" ]]; then
  echo "Refusing app-root storage; remove it and configure external runtime storage." >&2
  exit 2
fi

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer2}"
"$PHP_BIN" -v | head -n 1
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || { echo "PHP 8.2+ is required." >&2; exit 2; }

mkdir -p "$APP_CACHE"
chmod -R u+rwX "$RUNTIME_REAL/storage" "$APP_CACHE"

# Install exactly the lockfile dependencies. Production installs omit dev packages.
"$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# Clear compiled Laravel caches after each manual deployment run.
"$PHP_BIN" artisan optimize:clear

cat <<'DONE'
Production dependencies installed and Laravel caches cleared.
Runtime .env and storage are outside public_html in billing-system-runtime.
No links or public copies were created; no database migrations were run.
DONE
