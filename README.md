# UniqueMirror Laravel API on Hostinger

Hostinger's Git screen for this website allows deployment to `public_html` or a subfolder only. Deploy this repository's `main` branch to **`public_html` with the subfolder field blank**. The repository root now contains Laravel's web entry point and rewrite rules.

```text
domains/uniquemirror.nobisystems.com/
|-- billing-system-runtime/       Private .env and storage; create over SSH
`-- public_html/                 Git deployment root
    |-- index.php                Boots the Laravel application below
    |-- .htaccess                Blocks direct access to app source and Git files
    `-- billing-system-laravel/   Laravel source and installed vendor
        `-- .htaccess            Denies direct HTTP access to this whole directory
```

The Laravel source is physically inside the web root because of Hostinger's Git destination limit. Both `.htaccess` files deny direct requests for it; the production `.env` and uploaded files live in `billing-system-runtime` **outside** the web root. Do not remove either deny rule. Git does not contain credentials, `vendor/`, uploaded media, or database data.

## First setup after Git deployment

Use SSH in `domains/uniquemirror.nobisystems.com/public_html/billing-system-laravel`:

1. Create the external runtime directory: `mkdir -p ../../billing-system-runtime`. Copy `hostinger-package/.env.hostinger.example` to `../../billing-system-runtime/.env`. Fill in the MariaDB values from hPanel, a unique `APP_KEY`, the existing JWT signing secret if old tokens must remain valid, HTTPS `APP_URL`, and the needed mail/FCM/AI credentials. Restrict the file with `chmod 600 ../../billing-system-runtime/.env`.
2. Run `bash hostinger-package/scripts/deploy.sh`. It installs locked Composer dependencies, prepares external storage, and clears Laravel caches. It does not change the database.
3. Run `php hostinger-package/scripts/mariadb-preflight.php`. After its checks pass and a database backup is available, run `php artisan migrate --force`.
4. Verify authentication, billing, `/api/...`, and `/files/{filename}` using representative requests before sending production traffic here.

See the [Hostinger package guide](billing-system-laravel/hostinger-package/README.md) for the full setup. Hostinger's [Git integration guide](https://www.hostinger.com/support/1583302-how-to-deploy-a-git-repository-in-hostinger/) documents automatic updates from the selected branch.

## Later pushes

Hostinger can sync PHP source and public files from `main` automatically. Route and config caches remain uncompiled, so source updates take effect after Git sync. If `composer.lock` changes, rerun `deploy.sh` over SSH. Review and run new migrations manually; Git file deployment does not apply them. Preserve and back up external `billing-system-runtime/` and the MariaDB database across deployments.
