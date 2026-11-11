# UniqueMirror Laravel API: Hostinger Git deployment

This repository contains the Laravel API and the files served by Hostinger's web root. It does not contain production credentials, Composer's `vendor/` directory, uploaded media, or database data.

```text
domains/uniquemirror.nobisystems.com/
├── billing-system-laravel/  Laravel application, private .env, vendor, storage
└── public_html/             Web entry point, rewrite rules, static assets
```

## Connect Hostinger Git

In hPanel's Git integration, select this repository and the `main` branch. **Set the deployment root to the domain directory that contains `public_html`**, for example `/home/<account>/domains/uniquemirror.nobisystems.com/` after verifying the exact path in hPanel. The default `public_html` target is unsafe for this repository because it would put Laravel application source in the web root. If hPanel does not permit the domain directory as the Git deployment root, stop and use a deployment method that can place the two sibling folders separately.

Hostinger's [Git integration guide](https://www.hostinger.com/support/1583302-how-to-deploy-a-git-repository-in-hostinger/) documents selecting a branch and root directory, and automatic deployment on later pushes. Confirm the target directory before enabling auto-deployment; Git deployment can overwrite files there.

## First installation after Git sync

Use SSH in `billing-system-laravel/`:

1. Copy `hostinger-package/.env.hostinger.example` to `.env`. Fill in the MariaDB host/name/user/password from hPanel, a unique `APP_KEY`, the existing JWT signing secret if existing tokens must remain valid, the HTTPS `APP_URL`, and the needed mail/FCM/AI credentials. Keep `.env` private and outside Git.
2. Run `bash hostinger-package/scripts/deploy.sh`. This installs locked Composer dependencies, prepares writable directories, syncs the public entry files, and clears Laravel caches. It does not migrate the database.
3. Run `php hostinger-package/scripts/mariadb-preflight.php`. Only after its checks pass and a database backup is available, run `php artisan migrate --force`.
4. Verify authentication, billing, `/api/...`, and `/files/{filename}` with representative requests before directing production traffic here.

The full procedure and prerequisites are in [the Hostinger package guide](billing-system-laravel/hostinger-package/README.md).

## Later pushes

Hostinger can sync code and `public_html` files from `main` on each push. Laravel route/config caches are cleared during first setup and should remain uncompiled so source changes take effect on Git sync. If `composer.lock` changes, rerun the deployment script over SSH. Review and run new migrations manually; Git file deployment does not apply them. Preserve and back up `.env`, `storage/`, and the MariaDB database across deployments.
