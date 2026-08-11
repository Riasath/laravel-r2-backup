<?php

namespace Riasath\R2Backup;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
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
