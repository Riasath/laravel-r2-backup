<?php

namespace Riasath\R2Backup\Console;

use Illuminate\Console\Command;
use Riasath\R2Backup\BackupService;
use Riasath\R2Backup\Exceptions\BackupFailed;
use Throwable;

/**
 * Takes a backup from the command line, so the same code path can be driven by
 * the scheduler, a deploy hook, or a cron entry on hosts with no queue worker.
 *
 *     php artisan r2-backup:run
 *     php artisan r2-backup:run --list
 */
class RunBackupCommand extends Command
{
    protected $signature = 'r2-backup:run
                            {--list : Show the backups already stored, without taking a new one}';

    protected $description = 'Dump the database and upload it to Cloudflare R2';

    public function handle(BackupService $backups): int
    {
        if (! $backups->configured()) {
            $this->components->error('Cloudflare R2 is not configured.');
            $this->line('  Missing: <fg=yellow>'.implode(', ', $backups->missingConfig()).'</>');

            return self::FAILURE;
        }

        $this->showUsage($backups);

        if ($this->option('list')) {
            return $this->listBackups($backups);
        }

        $this->components->info('Backing up the database…');

        try {
            $result = $backups->run();
        } catch (BackupFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('The backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('File', $result['name']);
        $this->components->twoColumnDetail('Size', BackupService::humanBytes($result['bytes']));
        $this->components->twoColumnDetail('Took', $result['seconds'].'s');
        $this->newLine();
        $this->components->info('Uploaded to '.config('r2-backup.bucket').'/'.$result['key']);

        return self::SUCCESS;
    }

    /**
     * Report the storage allowance when the guard is switched on, so an
     * operator can see the headroom shrinking before a run is refused.
     */
    protected function showUsage(BackupService $backups): void
    {
        $guard = $backups->guard();

        if (! $guard->enabled()) {
            return;
        }

        if ($missing = $guard->missingConfig()) {
            $this->components->warn('Free-tier check is on but unconfigured. Missing: '.implode(', ', $missing));

            return;
        }

        if (! $usage = $guard->usage()) {
            $this->components->warn('Free-tier check is on, but Cloudflare usage could not be read.');

            return;
        }

        $threshold = (float) config('r2-backup.free_tier.threshold', 0.10);
        $low = $usage->remainingRatio() < $threshold;

        $this->components->twoColumnDetail(
            'R2 storage',
            ($low ? '<fg=red>' : '<fg=green>').$usage->summary().'</>',
        );
    }

    /**
     * Print the backups already in the bucket.
     */
    protected function listBackups(BackupService $backups): int
    {
        try {
            $stored = $backups->all();
        } catch (Throwable $e) {
            $this->components->error('Could not list backups: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($stored->isEmpty()) {
            $this->components->warn('No backups stored yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Backup', 'Size', 'Taken'],
            $stored->map(fn (array $b) => [
                $b['name'],
                BackupService::humanBytes($b['bytes']),
                $b['at']?->diffForHumans() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
