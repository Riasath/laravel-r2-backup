<?php

namespace Riasath\R2Backup;

use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use PDO;
use Riasath\R2Backup\Exceptions\BackupFailed;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dumps the application database, compresses it, and uploads the result to
 * Cloudflare R2 (or any other configured Flysystem disk).
 *
 * The dump is streamed straight into a gzip handle and streamed again on
 * upload, so a database of any size costs roughly a constant amount of memory.
 * Temporary files are always removed, even when a step fails part-way through.
 *
 * MySQL credentials reach mysqldump through a short-lived 0600 defaults file
 * rather than argv — command lines are world-readable via `ps`, and a database
 * password must not leak to every other user on the machine.
 */
class BackupService
{
    /**
     * Whether every required credential is present.
     */
    public function configured(): bool
    {
        return $this->missingConfig() === [];
    }

    /**
     * Names of the required config values that are still blank, so the caller
     * can say exactly what to fill in rather than failing on first use.
     *
     * @return array<int, string>
     */
    public function missingConfig(): array
    {
        // A disk the host application defines itself is its own business.
        if (! config('r2-backup.register_disk', true)) {
            return [];
        }

        $required = [
            'R2_ACCESS_KEY_ID' => config('r2-backup.key'),
            'R2_SECRET_ACCESS_KEY' => config('r2-backup.secret'),
            'R2_BUCKET' => config('r2-backup.bucket'),
            'R2_ENDPOINT' => config('r2-backup.endpoint'),
        ];

        return array_keys(array_filter($required, static fn ($value) => blank($value)));
    }

    /**
     * Dump the database, compress it, upload it, and prune old copies.
     *
     * @return array{name: string, key: string, bytes: int, seconds: float}
     *
     * @throws BackupFailed
     */
    public function run(): array
    {
        if (! $this->configured()) {
            throw BackupFailed::notConfigured($this->missingConfig());
        }

        // Ask about the storage allowance before doing any work, so a full
        // account fails in a second rather than after a long dump.
        $this->guard()->check();

        $started = microtime(true);
        $name = $this->filename();
        $local = $this->tempPath($name);

        try {
            $connection = DB::connection(config('r2-backup.connection'));

            match ($driver = $connection->getDriverName()) {
                'mysql', 'mariadb' => $this->dumpMysql($connection, $local),
                'sqlite' => $this->dumpSqlite($connection, $local),
                default => throw BackupFailed::unsupportedDriver($driver),
            };

            $key = $this->key($name);
            $bytes = filesize($local) ?: 0;

            // The exact size is only knowable now. Re-check against it, from the
            // cached reading, so a large dump cannot slip through on a figure
            // that merely cleared the floor before the dump existed.
            $this->guard()->check($bytes);

            $stream = fopen($local, 'rb');

            if ($stream === false) {
                throw new BackupFailed('The database dump could not be reopened for upload.');
            }

            try {
                $this->disk()->writeStream($key, $stream);
            } finally {
                // writeStream closes the handle on success; this guards the
                // failure path from leaking it.
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            // A pruning failure must not mask a backup that is already safely
            // uploaded — the operator would retry a run that actually worked.
            try {
                $this->prune();
            } catch (Throwable $e) {
                Log::warning('R2 backup uploaded, but pruning old copies failed.', ['exception' => $e]);
            }

            // The freshly uploaded object makes any cached usage reading stale.
            $this->guard()->forget();

            return [
                'name' => $name,
                'key' => $key,
                'bytes' => $bytes,
                'seconds' => round(microtime(true) - $started, 2),
            ];
        } finally {
            if (is_file($local)) {
                @unlink($local);
            }
        }
    }

    /**
     * Existing backups, newest first.
     *
     * @return Collection<int, array{name: string, key: string, bytes: int, at: Carbon|null}>
     */
    public function all(?int $limit = null): Collection
    {
        // listContents carries size and mtime in the same ListObjects response,
        // so this costs one API call rather than a HEAD request per file.
        $contents = $this->disk()->getDriver()->listContents($this->prefix(), false);

        $backups = (new Collection(iterator_to_array($contents)))
            ->filter(static fn (StorageAttributes $item) => $item->isFile())
            ->map(static fn (FileAttributes $item) => [
                'name' => basename($item->path()),
                'key' => $item->path(),
                'bytes' => $item->fileSize() ?? 0,
                'at' => ($time = $item->lastModified()) ? Carbon::createFromTimestamp($time) : null,
            ])
            // Filenames are timestamped, so a reverse sort is newest-first.
            ->sortByDesc('name')
            ->values();

        return $limit ? $backups->take($limit) : $backups;
    }

    /**
     * Delete a single backup by filename.
     */
    public function delete(string $name): bool
    {
        return $this->disk()->delete($this->key($this->safeName($name)));
    }

    /**
     * Open a read stream for a backup, for streaming it to the browser.
     *
     * @return resource|null
     */
    public function readStream(string $name)
    {
        return $this->disk()->readStream($this->key($this->safeName($name)));
    }

    /**
     * Whether a given backup exists.
     */
    public function exists(string $name): bool
    {
        return $this->disk()->exists($this->key($this->safeName($name)));
    }

    /**
     * Delete everything beyond the configured retention count.
     *
     * @return int number of backups removed
     */
    public function prune(): int
    {
        $keep = (int) config('r2-backup.keep', 0);

        if ($keep <= 0) {
            return 0;
        }

        $stale = $this->all()->slice($keep);

        foreach ($stale as $backup) {
            $this->disk()->delete($backup['key']);
        }

        return $stale->count();
    }

    /**
     * The configured destination disk.
     */
    public function disk(): FilesystemAdapter
    {
        return Storage::disk(config('r2-backup.disk', 'r2'));
    }

    /**
     * The storage-allowance guard. Resolved lazily rather than injected, so
     * `new BackupService` keeps working for anyone constructing it by hand.
     */
    public function guard(): FreeTierGuard
    {
        return app(FreeTierGuard::class);
    }

    /**
     * Stream a mysqldump straight into a gzip file.
     */
    protected function dumpMysql(Connection $connection, string $target): void
    {
        $config = $connection->getConfig();
        $binary = (string) config('r2-backup.mysqldump_path', 'mysqldump');
        $defaults = $this->writeDefaultsFile($config);

        try {
            $command = array_merge(
                [$binary, '--defaults-extra-file='.$defaults],
                (array) config('r2-backup.mysqldump_options', []),
                [$config['database']],
            );

            $process = new Process($command);
            // A large database can legitimately take several minutes.
            $process->setTimeout(null);

            $gzip = gzopen($target, 'wb6');

            if ($gzip === false) {
                throw new BackupFailed("Could not open {$target} for writing.");
            }

            try {
                $process->run(function (string $type, string $buffer) use ($gzip, $process): void {
                    if ($type !== Process::OUT) {
                        return;
                    }

                    gzwrite($gzip, $buffer);

                    // Symfony buffers stdout internally as well as handing it to
                    // this callback; clearing keeps memory flat on big dumps.
                    $process->clearOutput();
                });
            } finally {
                gzclose($gzip);
            }

            if (! $process->isSuccessful()) {
                throw new BackupFailed($this->explainDumpFailure($process));
            }
        } catch (ProcessException $e) {
            throw BackupFailed::dumpToolMissing($binary, $e->getMessage());
        } finally {
            @unlink($defaults);
        }

        if (! is_file($target) || filesize($target) === 0) {
            throw new BackupFailed('mysqldump produced an empty file.');
        }
    }

    /**
     * Snapshot a SQLite database. VACUUM INTO writes a consistent copy even
     * while the application is mid-write, which a plain file copy cannot.
     *
     * The snapshot runs on a dedicated PDO connection rather than the
     * application's own. SQLite refuses to VACUUM inside a transaction, and an
     * app may well be holding one open — during a test wrapped in
     * RefreshDatabase, or anywhere run() is called from inside DB::transaction().
     * A separate connection sidesteps that entirely, and reads exactly the
     * committed state a backup should capture.
     */
    protected function dumpSqlite(Connection $connection, string $target): void
    {
        $database = (string) $connection->getConfig('database');

        if ($database === ':memory:' || str_contains($database, 'mode=memory')) {
            throw new BackupFailed(
                'An in-memory SQLite database cannot be backed up — there is no file to snapshot. '
                .'Point the r2-backup.connection config at a file-based connection instead.'
            );
        }

        if (! is_file($database)) {
            throw new BackupFailed("The SQLite database file could not be found at {$database}.");
        }

        $snapshot = $target.'.sqlite';

        try {
            $pdo = new PDO('sqlite:'.$database);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // VACUUM INTO takes no bound parameters, so the path is quoted.
            $pdo->exec('VACUUM INTO '.$pdo->quote($snapshot));

            $in = fopen($snapshot, 'rb');
            $out = gzopen($target, 'wb6');

            if ($in === false || $out === false) {
                throw new BackupFailed('Could not compress the SQLite snapshot.');
            }

            try {
                while (! feof($in)) {
                    gzwrite($out, (string) fread($in, 524288));
                }
            } finally {
                fclose($in);
                gzclose($out);
            }
        } finally {
            if (is_file($snapshot)) {
                @unlink($snapshot);
            }
        }
    }

    /**
     * Write a 0600 defaults file holding the credentials, so the password
     * never appears in the process list.
     */
    protected function writeDefaultsFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'r2-backup-');

        if ($path === false) {
            throw new BackupFailed('Could not create a temporary credentials file.');
        }

        chmod($path, 0600);

        file_put_contents($path, implode("\n", [
            '[client]',
            'host='.($config['host'] ?? '127.0.0.1'),
            'port='.($config['port'] ?? 3306),
            'user='.($config['username'] ?? ''),
            // Quoted so passwords containing # or spaces survive the parser.
            'password="'.str_replace('"', '\"', (string) ($config['password'] ?? '')).'"',
            '',
        ]));

        return $path;
    }

    /**
     * Turn mysqldump's stderr into something an operator can act on.
     */
    protected function explainDumpFailure(Process $process): string
    {
        $stderr = trim($process->getErrorOutput()) ?: 'mysqldump exited with code '.$process->getExitCode().'.';

        if (str_contains($stderr, 'Access denied')) {
            return 'The database user is not allowed to read the database. Grant it SELECT, '
                .'LOCK TABLES and SHOW VIEW, then try again. ('.$stderr.')';
        }

        return $stderr;
    }

    /**
     * Timestamped object name, e.g. "my-shop-2026-08-11-143022.sql.gz".
     */
    protected function filename(): string
    {
        $prefix = config('r2-backup.filename_prefix') ?: Str::slug((string) config('app.name'));

        return ($prefix ?: 'backup').'-'.now()->format('Y-m-d-His').'.sql.gz';
    }

    /**
     * The bucket prefix, without a trailing slash.
     */
    protected function prefix(): string
    {
        return trim((string) config('r2-backup.prefix', 'backups'), '/');
    }

    /**
     * Full object key for a backup filename.
     */
    protected function key(string $name): string
    {
        $prefix = $this->prefix();

        return $prefix === '' ? $name : $prefix.'/'.$name;
    }

    /**
     * Reduce a user-supplied name to a bare filename, so a crafted value can
     * never traverse out of the backup prefix.
     */
    protected function safeName(string $name): string
    {
        return basename(str_replace('\\', '/', $name));
    }

    /**
     * Absolute path for the working file, creating the directory on first use.
     */
    protected function tempPath(string $name): string
    {
        $directory = storage_path('app/r2-backup');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Working files are transient; never let them reach version control.
        if (! is_file($gitignore = $directory.'/.gitignore')) {
            file_put_contents($gitignore, "*\n!.gitignore\n");
        }

        return $directory.'/'.$name;
    }

    /**
     * Human-readable byte size, e.g. "4.2 MB".
     */
    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $value = $bytes;
        $last = array_key_last($units);

        foreach ($units as $i => $unit) {
            $value /= 1024;
            $rounded = round($value, $value < 10 ? 1 : 0);

            // Test the rounded figure, not the raw one: 1073741633 bytes is
            // 1023.99 MB, which prints as "1024 MB" unless it is promoted here.
            if ($rounded < 1024 || $i === $last) {
                return $rounded.' '.$unit;
            }
        }

        return round($value, 1).' PB';
    }
}
