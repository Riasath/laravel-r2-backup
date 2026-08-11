<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare R2 Credentials
    |--------------------------------------------------------------------------
    |
    | Create a bucket in the Cloudflare dashboard, then an API token scoped to
    | it with "Object Read & Write". The endpoint is your account endpoint and
    | does NOT include the bucket name:
    |
    |     https://<account-id>.r2.cloudflarestorage.com
    |
    */

    'key' => env('R2_ACCESS_KEY_ID'),
    'secret' => env('R2_SECRET_ACCESS_KEY'),
    'bucket' => env('R2_BUCKET'),
    'endpoint' => env('R2_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk backups are written to. The package registers this
    | disk for you from the credentials above, so there is nothing to add to
    | config/filesystems.php.
    |
    | If you would rather point backups at a disk you already define yourself
    | (S3, Backblaze B2, Wasabi, a local path, anything Flysystem supports),
    | set 'register_disk' to false and name your own disk here.
    |
    */

    'disk' => env('R2_BACKUP_DISK', 'r2'),

    'register_disk' => true,

    /*
    |--------------------------------------------------------------------------
    | Object Prefix
    |--------------------------------------------------------------------------
    |
    | The "folder" inside the bucket where dumps are stored. Useful when one
    | bucket serves several applications — give each its own prefix.
    |
    */

    'prefix' => env('R2_BACKUP_PREFIX', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Filename Prefix
    |--------------------------------------------------------------------------
    |
    | Leading part of each dump's filename, before the timestamp. Defaults to a
    | slug of your app name, producing e.g. "my-shop-2026-08-11-143022.sql.gz".
    |
    */

    'filename_prefix' => env('R2_BACKUP_NAME', null),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | Which connection to dump. Null uses the application default. MySQL,
    | MariaDB and SQLite are supported.
    |
    */

    'connection' => env('R2_BACKUP_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | mysqldump Binary
    |--------------------------------------------------------------------------
    |
    | The bare name works wherever MySQL's client tools are on the PATH. Set an
    | absolute path on hosts where they are not — shared hosting often hides
    | them somewhere like /usr/local/mysql/bin/mysqldump.
    |
    */

    'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),

    /*
    | Extra flags appended to every mysqldump invocation. The defaults take a
    | consistent snapshot without locking tables, so your app keeps serving
    | traffic while the backup runs.
    */

    'mysqldump_options' => [
        '--single-transaction',
        '--routines',
        '--triggers',
        // Avoids requiring the PROCESS privilege on MySQL 8.
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many backups to keep. Older ones are deleted after each successful
    | run. Set to 0 to keep everything forever — but remember that an
    | unattended nightly backup will fill a bucket eventually.
    |
    */

    'keep' => (int) env('R2_BACKUP_KEEP', 0),

    /*
    |--------------------------------------------------------------------------
    | Free Tier Guard
    |--------------------------------------------------------------------------
    |
    | R2's free tier covers 10 GB of stored data, charged account-wide. Turn
    | this on and the package asks Cloudflare how much of that is already used
    | before it dumps anything, refusing to run once the remaining headroom
    | falls below 'threshold' — so an unattended nightly backup cannot quietly
    | push you onto a paid bill.
    |
    | This needs a SECOND Cloudflare token, separate from the R2 credentials at
    | the top of this file: dashboard → My Profile → API Tokens → Create Token,
    | with "Account Analytics: Read". Your account ID is the <account-id> part
    | of the R2 endpoint. S3 credentials cannot read usage.
    |
    | Cloudflare aggregates storage daily and lags writes by a few minutes, so
    | treat the reading as a close estimate rather than a ledger.
    |
    */

    'free_tier' => [

        'enabled' => (bool) env('R2_FREE_TIER_CHECK', false),

        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),

        /*
        | Gigabytes the allowance covers. Raise it on a paid plan to turn this
        | into a spend ceiling of your own choosing rather than a free-tier one.
        */
        'limit_gb' => (float) env('R2_FREE_TIER_LIMIT_GB', 10),

        /*
        | Stop when less than this fraction of the allowance is left, counting
        | the backup about to be uploaded. 0.10 is ten percent.
        */
        'threshold' => (float) env('R2_FREE_TIER_THRESHOLD', 0.10),

        /*
        | Seconds to reuse a reading. Cloudflare's numbers only move every few
        | minutes, and one run checks twice. Set 0 to ask every time.
        */
        'cache_ttl' => (int) env('R2_FREE_TIER_CACHE_TTL', 300),

        /*
        | What to do when Cloudflare cannot be reached. A missed backup is worse
        | than a few cents of overage, so by default an unreachable API logs a
        | warning and lets the backup through. Set false to refuse instead.
        */
        'fail_open' => (bool) env('R2_FREE_TIER_FAIL_OPEN', true),

        'timeout' => (int) env('R2_FREE_TIER_TIMEOUT', 10),

    ],

    /*
    |--------------------------------------------------------------------------
    | Web Interface
    |--------------------------------------------------------------------------
    |
    | The package ships a ready-made backup screen. Set 'enabled' to false to
    | skip the routes entirely and drive backups from the artisan command or
    | the BackupService only.
    |
    | 'middleware' guards the routes — always keep an auth middleware here.
    | 'gate' optionally names a Gate ability that must also pass; define it in
    | your AuthServiceProvider, e.g. Gate::define('run-backups', fn ($user) =>
    | $user->role === 'admin').
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'admin/backup',
        'name' => 'r2-backup.',
        'middleware' => ['web', 'auth'],
        'gate' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | View Layout
    |--------------------------------------------------------------------------
    |
    | The blade layout the backup screen extends, and the section it renders
    | into. Point these at your own admin layout so the page inherits your
    | styling. Leave 'layout' null to use the package's self-contained page.
    |
    */

    'layout' => env('R2_BACKUP_LAYOUT', null),
    'section' => 'content',

];
