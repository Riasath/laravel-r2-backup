# Laravel R2 Backup

One-click database backup to **Cloudflare R2** for Laravel.

Dumps your database, gzips it, and streams it straight to object storage. No queue worker,
no local disk bloat, no `spatie/laravel-backup`-sized configuration surface.

- **Ships a ready-made backup screen** — take a backup, list them, download, delete.
- **Also an artisan command**, so the scheduler or a cron entry can drive it.
- **Constant memory** — the dump is streamed into gzip and streamed again on upload, so a
  20 GB database costs about as much RAM as a 20 MB one.
- **MySQL, MariaDB and SQLite.**
- **Your password never hits the process list** — credentials reach `mysqldump` through a
  short-lived `0600` defaults file, not `argv`.
- **Works on any S3-compatible store** — R2 by default, but point it at S3, Backblaze B2 or
  Wasabi with one config line.

---

## Requirements

| | |
|---|---|
| PHP | 8.2+ |
| Laravel | 11, 12 or 13 |
| For MySQL/MariaDB | `mysqldump` on the `PATH`, and PHP's `proc_open` not disabled |
| For SQLite | nothing extra |
| Everywhere | the `zlib` and `curl` extensions, and outbound HTTPS |

> **Shared hosting:** many cheap hosts disable `proc_open`/`exec`. Check with
> `php -r 'var_dump(function_exists("proc_open"));'` before you rely on this for MySQL.
> SQLite backups do not need it.

---

## Installation

### From Packagist

```bash
composer require riasath/laravel-r2-backup
```

### From a private GitHub repo

Add the repository to the host app's `composer.json`, then require it:

```bash
composer config repositories.r2backup vcs https://github.com/<you>/laravel-r2-backup
composer require riasath/laravel-r2-backup:^1.0
```

### From a local folder (developing the package)

```bash
composer config repositories.r2backup path ../packages/laravel-r2-backup
composer require "riasath/laravel-r2-backup:@dev"
```

> The `@dev` suffix is required. A path repository with no git tags resolves to `dev-main`,
> which Composer rejects under the default `"minimum-stability": "stable"`. Tag the package
> `v1.0.0` and you can drop the suffix.

The service provider is auto-discovered — there is nothing to register manually.

---

## Getting Cloudflare R2 credentials

1. Cloudflare dashboard → **R2** → **Create bucket**. Any name; keep it **private**.
2. **R2 → Manage R2 API Tokens → Create API Token**.
3. Permission: **Object Read & Write**. Scope it to just this bucket.
4. Copy the **Access Key ID** and **Secret Access Key** — the secret is shown only once.
5. Copy the **S3 endpoint**: `https://<account-id>.r2.cloudflarestorage.com`
   — the account endpoint, **without** the bucket name on the end.

R2's free tier is 10 GB of storage with **zero egress fees**, so downloading a backup costs
nothing.

---

## Configuration

Add to `.env`:

```dotenv
R2_ACCESS_KEY_ID=your-access-key-id
R2_SECRET_ACCESS_KEY=your-secret-access-key
R2_BUCKET=your-bucket-name
R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
```

That is the whole setup. The package defines its own filesystem disk from these values, so
you never touch `config/filesystems.php`.

Optional knobs:

```dotenv
R2_BACKUP_PREFIX=backups     # "folder" inside the bucket — give each app its own
R2_BACKUP_NAME=my-shop       # filename prefix (defaults to a slug of APP_NAME)
R2_BACKUP_KEEP=14            # delete all but the newest 14 after each run (0 = keep all)
R2_BACKUP_CONNECTION=mysql   # which database connection to dump
R2_BACKUP_LAYOUT=layouts.app # your blade layout, so the screen inherits your styling
MYSQLDUMP_PATH=/usr/local/mysql/bin/mysqldump
```

To publish the config file for deeper changes:

```bash
php artisan vendor:publish --tag=r2-backup-config
```

---

## Usage

### The web interface

Visit **`/admin/backup`**. Protected by the `web` and `auth` middleware out of the box.

To change where it lives, or turn it off entirely, publish the config and edit:

```php
'routes' => [
    'enabled'    => true,
    'prefix'     => 'admin/backup',
    'middleware' => ['web', 'auth'],
    'gate'       => 'run-backups',   // see below
],
```

### Locking it down

`auth` alone means *any* logged-in user can dump your entire database. Name a Gate ability
and it is enforced on every route:

```php
// config/r2-backup.php
'gate' => 'run-backups',
```

```php
// app/Providers/AppServiceProvider.php — boot()
Gate::define('run-backups', fn ($user) => in_array($user->role, ['admin', 'system_admin']));
```

### Matching your admin layout

Point the config at your own layout and the page renders inside your existing chrome:

```php
'layout'  => 'layouts.admin',   // your blade layout
'section' => 'content',         // the @yield it renders into
```

Leave `layout` as `null` and the package serves its own self-contained page.

For full control over the markup:

```bash
php artisan vendor:publish --tag=r2-backup-views
```

### From the command line

```bash
php artisan r2-backup:run            # take a backup
php artisan r2-backup:run --list     # show what is already stored
```

### On a schedule

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('r2-backup:run')->dailyAt('02:00');
```

Set `R2_BACKUP_KEEP` to something sensible before you do this, or the bucket grows forever.

### From your own code

```php
use Riasath\R2Backup\BackupService;

$result = app(BackupService::class)->run();
// ['name' => '...sql.gz', 'key' => 'backups/...', 'bytes' => 1234, 'seconds' => 0.6]

app(BackupService::class)->all();            // every stored backup, newest first
app(BackupService::class)->delete($name);
app(BackupService::class)->prune();          // enforce the retention limit now
```

`run()` throws `Riasath\R2Backup\Exceptions\BackupFailed` with an operator-readable
message when something goes wrong.

**On a large database**, run it from a queued job instead of the web button:

```php
dispatch(fn () => app(BackupService::class)->run());
```

---

## Restoring a backup

A backup you have never restored is a rumour, not a backup. Test this.

**MySQL / MariaDB:**

```bash
gunzip < my-shop-2026-08-11-020000.sql.gz | mysql -u root -p my_database
```

**SQLite** — the dump *is* the database file, gzipped:

```bash
gunzip < my-shop-2026-08-11-020000.sql.gz > database/database.sqlite
```

---

## Using a different storage provider

Any S3-compatible service works. Define your own disk in `config/filesystems.php`, then:

```php
// config/r2-backup.php
'disk'          => 'b2',    // your disk name
'register_disk' => false,   // stop the package defining its own
```

---

## Troubleshooting

**"Could not start mysqldump"** — the binary is not on the `PATH`. Find it with
`which mysqldump` and set `MYSQLDUMP_PATH` to the absolute path.

**"The database user is not allowed to read the database"** — grant `SELECT`, `LOCK TABLES`
and `SHOW VIEW` to the user in `DB_USERNAME`.

**`403 Forbidden` from R2** — the API token is not scoped to this bucket, or it is read-only.
It needs **Object Read & Write**.

**`SignatureDoesNotMatch`** — `R2_ENDPOINT` probably has the bucket name appended. It must be
the bare account endpoint.

**"An in-memory SQLite database cannot be backed up"** — there is no file to snapshot. Point
`R2_BACKUP_CONNECTION` at a file-based connection.

**The request times out** — the dump is running inline. Move it to a queued job (above).

---

## Security notes

- A dump contains **every password hash and all business data**. Keep the bucket private, and
  never expose the backup routes without both `auth` and a `gate`.
- Filenames from the URL are reduced to a bare basename before use, and the routes constrain
  them to `[A-Za-z0-9._-]+`, so a crafted path cannot escape the backup prefix.
- Database credentials are written to a `0600` temp file for `mysqldump` and deleted straight
  after, so they never appear in `ps` output.

---

## License

MIT.
