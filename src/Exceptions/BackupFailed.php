<?php

namespace Riasath\R2Backup\Exceptions;

use RuntimeException;

/**
 * Thrown when a backup cannot be produced or uploaded. The message is written
 * for the operator reading it on screen, not for a stack trace.
 */
class BackupFailed extends RuntimeException
{
    public static function notConfigured(array $missing): self
    {
        return new self(
            'Cloudflare R2 is not configured. Set '.implode(', ', $missing).' in your .env file.'
        );
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("Backups are not supported for the “{$driver}” database driver. MySQL, MariaDB and SQLite are supported.");
    }

    public static function dumpToolMissing(string $binary, string $reason): self
    {
        return new self(
            "Could not start “{$binary}”. Check that MySQL's client tools are installed and on the PATH, "
            ."or set MYSQLDUMP_PATH in .env to the absolute path. ({$reason})"
        );
    }
}
