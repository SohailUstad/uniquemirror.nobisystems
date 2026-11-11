# Hostinger Git deployment layout

Hostinger Git deploys this repository directly into the domain's `public_html`. The repository root `index.php` and `.htaccess` serve the site and route requests to Laravel in `billing-system-laravel`. Laravel's environment and writable data live in a sibling directory outside the web root.

```text
/home/<account>/domains/<domain>/
|-- billing-system-runtime/             # private .env and writable storage
|   |-- .env
|   `-- storage/
`-- public_html/
    |-- index.php                       # repository root front controller
    |-- .htaccess                       # repository root rewrite rules
    `-- billing-system-laravel/         # Laravel source and composer.lock
```

The Laravel bootstrap is configured to read runtime paths from `billing-system-runtime`; do not create `.env` or `storage` in the app checkout. The helper rejects either path if present there. Do not expose the Laravel source tree or runtime directory to HTTP; requests should enter through the root front controller.

## First setup

1. In hPanel, create a MariaDB database and user. Record the exact host, database name, and username shown by Hostinger. Select PHP 8.2 or newer and check the web and CLI PHP versions and required extensions (PDO MySQL, OpenSSL, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo, and GD if QR generation needs it). Enable SSH and check `php -v` and `composer2 --version`.
2. Connect the repository through Hostinger Git and deploy it directly to the domain's `public_html`. Confirm root `index.php`, root `.htaccess`, and `billing-system-laravel/artisan` are present.
3. Over SSH, create the external runtime file from the template:

   ```sh
   cd ~/domains/<domain>
   mkdir -p billing-system-runtime
   cp public_html/billing-system-laravel/hostinger-package/.env.hostinger.example billing-system-runtime/.env
   chmod 600 billing-system-runtime/.env
   ```

   Edit `billing-system-runtime/.env` with real database credentials, final HTTPS `APP_URL`, `APP_DEBUG=false`, and a unique `APP_KEY`. Generate an initial key value with `php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'` and insert it in the file. Configure SMTP before relying on OTP email; `MAIL_MAILER=log` does not deliver messages. Replace all `CHANGE_ME` database/JWT placeholders. Keep the same `APP_KEY` on later deployments.
4. Run the deployment helper from the app root:

   ```sh
   cd ~/domains/<domain>/public_html/billing-system-laravel
   bash hostinger-package/scripts/deploy.sh
   ```

   It checks the expected layout and external runtime `.env`, creates external writable storage directories, rejects app-root `.env` or `storage`, installs locked production Composer dependencies, and runs `php artisan optimize:clear`. It creates no links or public copies and does not run migrations. Set `PHP_BIN` or `COMPOSER_BIN` if the host's command names differ.
5. Inspect migration compatibility and take a database backup before schema changes. Run `php hostinger-package/scripts/mariadb-preflight.php` from the app root to check CLI requirements and MariaDB behavior. It uses only a temporary table. Only after reviewing its results should migrations be run manually with `php artisan migrate --force`.
6. Verify HTTPS, login/authentication, `/api/...`, and `/files/{filename}` with a safe test upload. Confirm the root front controller and rewrite rules are used, then check logs and permissions.

## Recurring pushes

Hostinger Git syncs pushed code to `public_html` automatically. For ordinary code pushes, no SSH deployment command is needed. If `composer.lock` changes, or Composer dependencies need repair, connect over SSH and run `bash hostinger-package/scripts/deploy.sh` from `public_html/billing-system-laravel`. Keep `billing-system-runtime/.env`, runtime storage, and the existing `APP_KEY` across pushes. Database migrations remain a separate reviewed manual operation.

The application currently uses synchronous queue processing and declares no scheduled tasks. If delayed jobs or scheduled tasks are added later, revisit the hosting setup; Hostinger cron uses UTC and shared Web hosting may not support a persistent queue worker.

## Package contents and references

- `.env.hostinger.example` provides a production-oriented MariaDB template without credentials.
- `scripts/deploy.sh` prepares external runtime storage and installs locked dependencies when needed.
- `scripts/mariadb-preflight.php` checks database compatibility using a temporary table.

Hostinger references: [SSH access](https://www.hostinger.com/support/1583645-how-to-enable-ssh-access/), [Composer](https://www.hostinger.com/support/5792078-how-to-use-composer-at-hostinger/), [CLI PHP and Composer](https://www.hostinger.com/support/5792082-how-to-solve-common-composer-issues-at-hostinger/), [PHP versions](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/), [document root](https://www.hostinger.com/support/1583494-what-is-the-path-to-your-website-s-root-home-directory-and-how-to-change-it-in-hostinger/), [MariaDB](https://www.hostinger.com/support/1583226-which-database-management-system-is-used-at-hostinger/), [cron jobs](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/).
