<?php

namespace Riasath\R2Backup;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Riasath\R2Backup\Console\RunBackupCommand;
use Riasath\R2Backup\Http\Middleware\Authorize;

class R2BackupServiceProvider extends ServiceProvider
{
    /**
     * Register bindings and synthesise the R2 disk.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/r2-backup.php', 'r2-backup');

        $this->registerDisk();

        $this->app->singleton(BackupService::class);
    }

    /**
     * Wire up routes, views, commands and publishable assets.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'r2-backup');

        $this->registerRoutes();

        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([RunBackupCommand::class]);

            $this->publishes([
                __DIR__.'/../config/r2-backup.php' => config_path('r2-backup.php'),
            ], 'r2-backup-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/r2-backup'),
            ], 'r2-backup-views');
        }
    }

    /**
     * Define the destination disk from the package's own credentials, so the
     * host application never has to touch config/filesystems.php.
     *
     * Skipped when 'register_disk' is false, letting an application point
     * backups at any disk it already defines (S3, B2, Wasabi, a local path).
     */
    protected function registerDisk(): void
    {
        /** @var Config $config */
        $config = $this->app->make('config');

        if (! $config->get('r2-backup.register_disk', true)) {
            return;
        }

        $name = $config->get('r2-backup.disk', 'r2');

        // Never clobber a disk the application has already defined itself.
        if ($config->has("filesystems.disks.{$name}")) {
            return;
        }

        $config->set("filesystems.disks.{$name}", [
            'driver' => 's3',
            'key' => $config->get('r2-backup.key'),
            'secret' => $config->get('r2-backup.secret'),
            // R2 has no regions; the SDK still requires the field.
            'region' => 'auto',
            'bucket' => $config->get('r2-backup.bucket'),
            'endpoint' => $config->get('r2-backup.endpoint'),
            'use_path_style_endpoint' => true,
            // Surface upload failures instead of returning false and letting
            // an operator believe their data is safe.
            'throw' => true,
            'report' => false,
        ]);
    }

    /**
     * Schedule the backup command, so an application gets nightly backups from
     * a config flag rather than by editing routes/console.php.
     *
     * Registered through callAfterResolving: the scheduler is only touched if
     * something actually builds one, which keeps a plain web request from
     * paying for a service it will never use.
     */
    protected function registerSchedule(): void
    {
        $settings = (array) $this->app->make('config')->get('r2-backup.schedule', []);

        if (! ($settings['enabled'] ?? false)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($settings): void {
            $time = static::normalizeTime((string) ($settings['time'] ?? '02:00'));

            $event = $schedule->command('r2-backup:run');

            match ($frequency = strtolower((string) ($settings['frequency'] ?? 'daily'))) {
                'daily' => $event->dailyAt($time),
                'weekly' => $event->weeklyOn((int) ($settings['day'] ?? 0), $time),
                'monthly' => $event->monthlyOn((int) ($settings['day'] ?? 1), $time),
                default => throw new InvalidArgumentException(
                    "r2-backup.schedule.frequency is “{$frequency}”. Use daily, weekly or monthly."
                ),
            };

            if ($timezone = $settings['timezone'] ?? null) {
                $event->timezone($timezone);
            }

            // A dump that runs past the next slot must not have a second one
            // start on top of it.
            if ($settings['without_overlapping'] ?? true) {
                $event->withoutOverlapping();
            }

            if ($settings['on_one_server'] ?? false) {
                $event->onOneServer();
            }
        });
    }

    /**
     * Turn a human-written time into the "HH:MM" the scheduler requires.
     *
     * "2:00 am", "2am", "2:00" and "02:00" all mean the same thing to a person
     * writing a config file, and none but the last is what dailyAt() wants.
     *
     * @throws InvalidArgumentException on anything that is not a time
     */
    public static function normalizeTime(string $time): string
    {
        $value = trim($time);

        if (! preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/i', $value, $matches)) {
            throw new InvalidArgumentException(
                "r2-backup.schedule.time is “{$time}”, which is not a time. Use 24-hour “02:00”, or “2:00 am”."
            );
        }

        $hour = (int) $matches[1];
        $minute = (int) ($matches[2] ?? 0);
        $meridiem = strtolower($matches[3] ?? '');

        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        }

        if ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        if ($hour > 23 || $minute > 59) {
            throw new InvalidArgumentException(
                "r2-backup.schedule.time is “{$time}”, which is not a real time of day."
            );
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Register the backup screen's routes, unless the application has turned
     * the web interface off.
     */
    protected function registerRoutes(): void
    {
        /** @var Config $config */
        $config = $this->app->make('config');

        $routes = (array) $config->get('r2-backup.routes', []);

        if (! ($routes['enabled'] ?? true)) {
            return;
        }

        Route::group([
            'prefix' => $routes['prefix'] ?? 'admin/backup',
            'as' => $routes['name'] ?? 'r2-backup.',
            // Authorize applies the optional gate and is always appended, so
            // an application cannot lose it by overriding 'middleware'.
            'middleware' => array_merge($routes['middleware'] ?? ['web', 'auth'], [Authorize::class]),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }
}
