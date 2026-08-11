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
