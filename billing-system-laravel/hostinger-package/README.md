# Hostinger Premium Web package

This package prepares the Laravel target for a private-app/public-web-root layout. It does not connect to Hostinger, create databases, upload files, move data, or switch traffic.

## Intended layout

```text
/home/<account>/domains/<domain>/
├── billing-system-laravel/    # full Laravel release, .env, vendor, storage; private sibling
└── public_html/                # public assets, this package's index.php and .htaccess only
```

Hostinger documents `public_html` as the usual web root and notes that the web root location cannot be changed on Web hosting. Confirm the actual directory in hPanel before using this layout. The packaged `public/index.php` boots `../billing-system-laravel`, and the rewrite rules route unmatched requests to Laravel, preserving root `/api/...` and `/files/{filename}` endpoints. Uploaded order media remains in Laravel's private local storage and is returned by the existing `/files/{filename}` controller route; do not expose `storage/` directly or create a public storage symlink.

## Requirements to confirm in hPanel

- Select PHP 8.2 or newer and verify required extensions (at least PDO MySQL, OpenSSL, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo, and GD if QR image generation requires it).
- Enable SSH. Premium Web currently includes SSH and Composer 2; CLI PHP can differ from the PHP version configured for web requests. Check `php -v`, `composer2 --version`, and the PHP version/extensions used by hPanel before installation. If CLI PHP is too old, Hostinger documents invoking Composer with an explicit PHP binary.
- Create a MariaDB database and user in hPanel. Use the exact host/name/user shown there; do not assume `localhost` if hPanel specifies another host. This package intentionally sets `DB_CONNECTION=mysql` because Laravel's MySQL PDO driver supports MariaDB.
- Configure outbound SMTP before relying on OTP email flows. `MAIL_MAILER=log` is a safe default for initial setup but will not deliver email.
- Confirm `APP_URL` uses the final HTTPS domain. Set `APP_DEBUG=false` and generate a unique `APP_KEY` once for this installation. Never commit or place a populated `.env` in `public_html`.

Hostinger references: [SSH access](https://www.hostinger.com/support/1583645-how-to-enable-ssh-access/), [Composer](https://www.hostinger.com/support/5792078-how-to-use-composer-at-hostinger/), [CLI PHP and Composer](https://www.hostinger.com/support/5792082-how-to-solve-common-composer-issues-at-hostinger/), [PHP versions](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/), [document root](https://www.hostinger.com/support/1583494-what-is-the-path-to-your-website-s-root-home-directory-and-how-to-change-it-in-hostinger/), [MariaDB](https://www.hostinger.com/support/1583226-which-database-management-system-is-used-at-hostinger/), [cron jobs](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/).

## Package contents

- `public/index.php` — private sibling app entry point.
- `public/.htaccess` — rewrite, authorization header forwarding, and directory listing protection.
- `.env.hostinger.example` — production-oriented MariaDB environment template with no credentials.
- `scripts/deploy.sh` — install locked production Composer dependencies, sync Laravel's public assets, install entrypoint/rules, set writable runtime dirs, and clear Laravel caches. It does not run migrations or touch the database.
- `scripts/validate-package.ps1` — local content/layout checks.
- `scripts/mariadb-preflight.php` — checks CLI PHP requirements and database version, stored generated columns, unique indexes, and case-sensitive collation behavior using only a temporary table.

## Deployment procedure (manual, when authorized)

1. Back up existing website files and database. Confirm the exact domain root in hPanel. Do not run deployment from `public_html`.
2. Upload the Laravel release into `domains/<domain>/billing-system-laravel/`, preserving the lockfile. Upload this package under that private app directory, for example as `hostinger-package/`. Keep `.env` and runtime storage private. Existing assets in `public_html` may be replaced on filename collision; review the current site's files before syncing.
3. Copy `.env.hostinger.example` to `billing-system-laravel/.env`, fill in real values via SSH, and restrict its permissions (`chmod 600 .env`). Before Composer has installed `vendor`, generate a unique key with `php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'` and place that output in `APP_KEY`. Never regenerate it on later releases because that invalidates encrypted data/cookies.
4. From the app root, run `bash hostinger-package/scripts/deploy.sh`. Script requires PHP 8.2+, `composer2`, and the sibling `public_html`. It copies files from Laravel's `public/` into `public_html/`, then replaces `index.php` and `.htaccess`. It clears Laravel caches so later Git file deployments do not serve stale routes or configuration. Inspect the existing public root first; the script does not delete unrelated files.
5. From the private Laravel app root, run `php hostinger-package/scripts/mariadb-preflight.php` after setting `.env`. Review every PASS/FAIL result before any schema changes. The script connects using the app's DB settings, prints no credentials, and creates only a random-name session temporary table; it drops that table in a `finally` cleanup and closes the session on exit. It does not read or alter application tables. It requires MariaDB 10.3+ and PHP CLI 8.2+ with the listed extensions. A failed check exits with status 1. Back up first, then run `php artisan migrate --force` manually only after compatibility review. This package avoids automatic migrations because this Laravel target's migrations/data have not been validated against production MariaDB.
6. Confirm HTTPS, login/authentication, `/api/...`, and `/files/{filename}` with a known safe test upload. Check logs and permissions. Preserve the same `APP_KEY` across releases.

The bill notification job runs with `QUEUE_CONNECTION=sync` after the HTTP response, so no persistent worker is needed for the current implementation. No scheduled tasks are declared. If code later adds delayed jobs or `Schedule` tasks, revisit this: Hostinger cron jobs run in UTC, and Hostinger's Laravel guidance shows `php artisan schedule:run` as a custom cron command. A persistent queue worker is generally not available as a long-lived process on shared Web hosting; use synchronous jobs or an external queue worker if required.

The AI input guard uses the locked `yethee/tiktoken` package and a pinned `cl100k_base` vocabulary in private `resources/tiktoken/`. The archive builder verifies the vocabulary SHA-256 hash, and the app reads it locally, so AI requests do not fetch tokenizer data from the internet. The configured AI provider still needs an API key and outbound HTTPS.

## Build a Hostinger upload archive

Run from the repository root in Windows PowerShell:

```powershell
.\migration-staging\phase7\hostinger_package\scripts\build-release.ps1
```

The source is bounded to `C:\xampp\htdocs\billing-system-laravel` and is read only. The ZIP is written to `migration-staging/phase7/hostinger_package/release-artifacts/billing-system-laravel-hostinger.zip`. It contains Laravel application source, `composer.lock`, eligible public static files, and this package under `hostinger-package/`; install production dependencies on the host with the documented deploy script. The builder excludes `.env`, `vendor`, test artifacts, caches/logs, `.git`, and non-web files under `public/`. It inspects paths and scans text entries for credential-like content and sensitive values from the local `.env`. A failed check stops the build. It never uploads or changes the target application.

## Local validation

From PowerShell at the repository root:

```powershell
.\migration-staging\phase7\hostinger_package\scripts\validate-package.ps1
```

This validates required package files and key layout/security assumptions. It does not test Hostinger connectivity or execute deployment. To run the live capability check on the target host, use `php hostinger-package/scripts/mariadb-preflight.php` from the private app root; that check requires the DB connection configured and performs only temporary-table operations.
