<?php

namespace Riasath\R2Backup\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Riasath\R2Backup\BackupService;
use Riasath\R2Backup\Exceptions\BackupFailed;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The backup screen: list what is stored, take a new dump, download one back,
 * or delete one.
 *
 * Backups run inline rather than on a queue, so the package works on hosts
 * with no worker process. That is fine for the small-to-medium databases this
 * is aimed at — a few hundred megabytes finishes well inside one request. If
 * yours grows past that, call BackupService::run() from a queued job instead.
 */
class BackupController extends Controller
{
    public function __construct(protected BackupService $backups) {}

    /**
     * Show the backup screen.
     */
    public function index(): View
    {
        if (! $this->backups->configured()) {
            return view('r2-backup::backup', [
                'configured' => false,
                'missing' => $this->backups->missingConfig(),
                'backups' => new Collection,
                'listError' => null,
                'bucket' => config('r2-backup.bucket'),
            ]);
        }

        // Listing reaches the network, so a credential or connectivity problem
        // should render as an on-screen message, not a stack trace.
        $listError = null;

        try {
            $backups = $this->backups->all();
        } catch (Throwable $e) {
            $backups = new Collection;
            $listError = $e->getMessage();
        }

        return view('r2-backup::backup', [
            'configured' => true,
            'missing' => [],
            'backups' => $backups,
            'listError' => $listError,
            'bucket' => config('r2-backup.bucket'),
        ]);
    }

    /**
     * Take a new backup.
     */
    public function store(Request $request): RedirectResponse
    {
        // One backup a minute per user: dumps are expensive, and an impatient
        // double-click should not start a second one.
        $key = 'r2-backup:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return back()->with('r2-backup.error', sprintf(
                'A backup was just started. Please wait %d seconds before taking another.',
                RateLimiter::availableIn($key),
            ));
        }

        RateLimiter::hit($key, 60);

        // The dump streams to disk and then to R2; neither step should be cut
        // short by the default execution limit.
        @set_time_limit(0);

        try {
            $result = $this->backups->run();
        } catch (BackupFailed $e) {
            return back()->with('r2-backup.error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('R2 backup failed', ['exception' => $e]);

            return back()->with('r2-backup.error', 'The backup failed: '.$e->getMessage());
        }

        return back()->with('r2-backup.status', sprintf(
            'Backup complete — %s (%s) uploaded in %ss.',
            $result['name'],
            BackupService::humanBytes($result['bytes']),
            $result['seconds'],
        ));
    }

    /**
     * Stream a stored backup to the browser.
     */
    public function download(string $name): StreamedResponse
    {
        abort_unless($this->backups->exists($name), 404);

        $stream = $this->backups->readStream($name);

        abort_if($stream === null, 404);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, basename($name), ['Content-Type' => 'application/gzip']);
    }

    /**
     * Delete a stored backup.
     */
    public function destroy(string $name): RedirectResponse
    {
        try {
            $this->backups->delete($name);
        } catch (Throwable $e) {
            return back()->with('r2-backup.error', 'Could not delete that backup: '.$e->getMessage());
        }

        return back()->with('r2-backup.status', basename($name).' deleted.');
    }
}
