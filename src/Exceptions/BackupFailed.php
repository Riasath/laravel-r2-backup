<?php

namespace Riasath\R2Backup\Exceptions;

use Riasath\R2Backup\BackupService;
use Riasath\R2Backup\FreeTierUsage;
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

    /**
     * The storage allowance is nearly gone, so nothing was uploaded.
     */
    public static function freeTierExhausted(FreeTierUsage $usage, float $threshold, int $incoming = 0): self
    {
        $outcome = $incoming > 0
            ? sprintf(
                'this %s backup would leave only %s',
                BackupService::humanBytes($incoming),
                BackupService::humanBytes($usage->remainingAfter($incoming)),
            )
            : sprintf('only %s remains', BackupService::humanBytes($usage->remaining()));

        return new self(sprintf(
            'Backup stopped — the Cloudflare R2 storage allowance is nearly used up. %s of %s is stored and %s, '
            .'below the %s%% floor. Free space by lowering R2_BACKUP_KEEP, delete old backups by hand, or raise '
            .'R2_FREE_TIER_LIMIT_GB if you are on a paid plan.',
            BackupService::humanBytes($usage->used),
            BackupService::humanBytes($usage->limit),
            $outcome,
            rtrim(rtrim(number_format($threshold * 100, 1), '0'), '.'),
        ));
    }

    /**
     * The guard is on but has no credentials to check with. Refusing here is
     * deliberate: silently skipping the check would defeat the point of it.
     */
    public static function freeTierNotConfigured(array $missing): self
    {
        return new self(
            'The R2 free-tier check is enabled but not configured. Set '.implode(', ', $missing).' in your .env file, '
            .'or set R2_FREE_TIER_CHECK=false to turn the check off. Note this is a separate Cloudflare API token '
            .'with "Account Analytics: Read" — your R2 S3 credentials cannot read usage.'
        );
    }

    /**
     * Cloudflare could not be reached and the operator chose to fail closed.
     */
    public static function freeTierUnavailable(string $reason): self
    {
        return new self(
            'Could not check the R2 storage allowance, and R2_FREE_TIER_FAIL_OPEN is false so the backup was not '
            ."attempted. ({$reason})"
        );
    }
}
