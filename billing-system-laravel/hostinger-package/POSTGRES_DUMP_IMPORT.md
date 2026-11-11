# Import the reviewed PostgreSQL dump into Hostinger MariaDB

This importer is for the `postgres_all_databases.sql` backup reviewed on 2026-11-12. It reads the 58 `COPY public...` tables, maps their columns to the Laravel schema, and imports 17,490 rows. It does not run PostgreSQL role or schema SQL. Upload files are separate from this database import.

The source contains seven historical references to deleted clients or orders. The Laravel migration `2026_11_12_020000_align_legacy_reference_constraints` preserves those rows by aligning two foreign key rules with the source database. The importer checks all other target foreign keys after loading.

## On Hostinger SSH

1. Back up the current MariaDB database. Confirm the Laravel `.env` points to the intended `u155343470_uniquemirror` database. Run the three new migrations after the Git deployment:

   ```sh
   cd ~/domains/uniquemirror.nobisystems.com/public_html/billing-system-laravel
   php artisan migrate --force
   ```

2. Put the dump at `~/domains/uniquemirror.nobisystems.com/billing-system-runtime/imports/postgres_all_databases.sql`, outside `public_html`. Create the private directory first with `mkdir -p ~/domains/uniquemirror.nobisystems.com/billing-system-runtime/imports`. Use SFTP or hPanel File Manager to upload the file. Do not add it to Git. Limit access with `chmod 600` on the uploaded file.

3. Check its SHA-256 and run the dry run. For the exact dump inspected locally, SHA-256 is `fe2ca3eaa091e0336679eb354bcf793d7b19c2b3b476e2cb89637a4dfde87887`. If this differs on Hostinger, stop and review the file before importing.

   ```sh
   cd ~/domains/uniquemirror.nobisystems.com/public_html/billing-system-laravel
   sha256sum ../../billing-system-runtime/imports/postgres_all_databases.sql
   php hostinger-package/scripts/import-postgres-dump.php ../../billing-system-runtime/imports/postgres_all_databases.sql
   ```

4. The target source tables must be empty. If they are, execute once:

   ```sh
   php hostinger-package/scripts/import-postgres-dump.php ../../billing-system-runtime/imports/postgres_all_databases.sql --execute --database=u155343470_uniquemirror --sha256=fe2ca3eaa091e0336679eb354bcf793d7b19c2b3b476e2cb89637a4dfde87887
   ```

   Expected result: `IMPORT COMPLETE: 58 tables, 17490 rows.` The importer checks its database name, dump hash, applied migrations, empty target tables, row counts, and foreign keys. On an import failure it rolls back the inserted rows and reports the table and row number without printing data values.

5. After success, move or delete the dump from the server's runtime directory according to your backup policy. Retain the original backup safely elsewhere. If the PostgreSQL application accepted changes after this dump was created, those later changes are absent and require a newer backup before final cutover.

Do not rerun the importer against populated source tables. Missing media files do not block the database import, but the associated file URLs will not serve until those files are restored separately.
