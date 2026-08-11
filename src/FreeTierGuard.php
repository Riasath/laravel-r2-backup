<?php

namespace Riasath\R2Backup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Riasath\R2Backup\Exceptions\BackupFailed;
use Riasath\R2Backup\Exceptions\UsageUnreadable;
use Throwable;

/**
 * Refuses to start a backup once the Cloudflare R2 storage allowance is nearly
 * gone, so an unattended nightly job cannot quietly push an account onto a bill.
 *
 * Usage comes from Cloudflare's GraphQL analytics API rather than from summing
 * the bucket, because the free tier is charged account-wide: a second bucket
 * you forgot about still counts against the same 10 GB. That accuracy costs a
 * separate API token — the R2 S3 credentials cannot read analytics.
 *
 * Cloudflare aggregates storage per day and lags real writes by a few minutes,
 * so readings are cached and treated as an estimate, not a ledger.
 */
class FreeTierGuard
{
    protected const ENDPOINT = 'https://api.cloudflare.com/client/v4/graphql';

    protected const CACHE_KEY = 'r2-backup:free-tier-usage';

    /**
     * Whether the guard is switched on at all.
     */
    public function enabled(): bool
    {
        return (bool) config('r2-backup.free_tier.enabled', false);
    }

    /**
     * Credentials the guard needs but does not have.
     *
     * @return array<int, string>
     */
    public function missingConfig(): array
    {
        $required = [
            'CLOUDFLARE_ACCOUNT_ID' => config('r2-backup.free_tier.account_id'),
            'CLOUDFLARE_API_TOKEN' => config('r2-backup.free_tier.api_token'),
        ];

        return array_keys(array_filter($required, static fn ($value) => blank($value)));
    }

    /**
     * Current usage for display, or null when the guard is off or Cloudflare
     * could not be reached. Never throws — a status line on a page must not be
     * able to break the page.
     */
    public function usage(): ?FreeTierUsage
    {
        if (! $this->enabled() || $this->missingConfig() !== []) {
            return null;
        }

        try {
            return $this->measure();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Stop the caller when too little of the allowance is left.
     *
     * @param  int  $incomingBytes  size of the backup about to be uploaded, when
     *                              known, so a large dump cannot slip through on
     *                              a reading that only just cleared the floor.
     *
     * @throws BackupFailed
     */
    public function check(int $incomingBytes = 0): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($missing = $this->missingConfig()) {
            throw BackupFailed::freeTierNotConfigured($missing);
        }

        try {
            $usage = $this->measure();
        } catch (UsageUnreadable $e) {
            // A network blip must not cost you the night's backup. Losing a
            // backup is worse than a few cents of overage, so an unreachable
            // API lets the run through unless the operator asked otherwise.
            //
            // Only transient failures land here. A bad token or account ID
            // raises BackupFailed from fetch() and passes straight through,
            // because a guard that silently stops guarding is worse than one
            // that stops the run.
            if (config('r2-backup.free_tier.fail_open', true)) {
                Log::warning('R2 free-tier check skipped — Cloudflare usage unreadable.', ['reason' => $e->getMessage()]);

                return;
            }

            throw BackupFailed::freeTierUnavailable($e->getMessage());
        }

        $threshold = (float) config('r2-backup.free_tier.threshold', 0.10);

        if ($usage->remainingRatioAfter($incomingBytes) < $threshold) {
            throw BackupFailed::freeTierExhausted($usage, $threshold, $incomingBytes);
        }
    }

    /**
     * Read usage, cached — the second check inside one run must not cost a
     * second API call, and Cloudflare's numbers only move every few minutes.
     */
    protected function measure(): FreeTierUsage
    {
        $ttl = (int) config('r2-backup.free_tier.cache_ttl', 300);

        $data = $ttl > 0
            ? Cache::remember(self::CACHE_KEY, $ttl, fn () => $this->fetch())
            : $this->fetch();

        return new FreeTierUsage(
            used: (int) ($data['used'] ?? 0),
            limit: $this->limitBytes(),
            objects: (int) ($data['objects'] ?? 0),
            measuredOn: $data['date'] ?? null,
        );
    }

    /**
     * The allowance in bytes.
     */
    protected function limitBytes(): int
    {
        return (int) round(((float) config('r2-backup.free_tier.limit_gb', 10)) * 1024 ** 3);
    }

    /**
     * Ask Cloudflare how many bytes this account has stored in R2.
     *
     * @return array{used: int, objects: int, date: string|null}
     *
     * @throws BackupFailed
     */
    protected function fetch(): array
    {
        $account = (string) config('r2-backup.free_tier.account_id');

        // Validated rather than parameterised: the value is interpolated into
        // the query, and a Cloudflare account ID is always 32 hex characters.
        if (! preg_match('/^[a-f0-9]{32}$/i', $account)) {
            throw new BackupFailed(
                'CLOUDFLARE_ACCOUNT_ID does not look like an account ID. It is the 32-character '
                .'hex string in your R2 endpoint: https://<account-id>.r2.cloudflarestorage.com'
            );
        }

        // Storage is grouped by day. Asking from yesterday and taking the
        // newest row keeps a run just after midnight UTC from seeing nothing.
        $since = now()->subDay()->format('Y-m-d');

        $query = sprintf(
            'query { viewer { accounts(filter: {accountTag: "%s"}) { '
            .'r2StorageAdaptiveGroups(limit: 1, filter: {date_geq: "%s"}, orderBy: [date_DESC]) { '
            .'max { payloadSize metadataSize objectCount } dimensions { date } } } } }',
            $account,
            $since,
        );

        try {
            $response = Http::withToken((string) config('r2-backup.free_tier.api_token'))
                ->timeout((int) config('r2-backup.free_tier.timeout', 10))
                ->acceptJson()
                ->post(self::ENDPOINT, ['query' => $query]);
        } catch (Throwable $e) {
            // DNS failure, refused connection, timeout — all transient.
            throw new UsageUnreadable('Could not reach Cloudflare: '.$e->getMessage(), previous: $e);
        }

        // An auth failure is an operator mistake, not a hiccup. Raising
        // BackupFailed here means fail_open cannot quietly bypass a guard the
        // operator believes is protecting them.
        if (in_array($response->status(), [401, 403], true)) {
            throw new BackupFailed(
                'Cloudflare rejected the analytics token (HTTP '.$response->status().'). It needs the '
                .'"Account Analytics: Read" permission, and it is NOT the same token as your R2 S3 credentials.'
            );
        }

        if ($response->failed()) {
            throw new UsageUnreadable('Cloudflare returned HTTP '.$response->status().' for the R2 usage query.');
        }

        $json = (array) $response->json();

        if (! empty($json['errors'])) {
            $message = (string) ($json['errors'][0]['message'] ?? 'unknown error');

            // GraphQL reports permission problems in the body with a 200, so
            // treat those as configuration too rather than as a blip.
            if (preg_match('/auth|permission|forbidden|denied/i', $message)) {
                throw new BackupFailed(
                    'Cloudflare rejected the usage query: '.$message.'. Check the token has '
                    .'"Account Analytics: Read" for this account.'
                );
            }

            throw new UsageUnreadable('Cloudflare rejected the usage query: '.$message);
        }

        $group = data_get($json, 'data.viewer.accounts.0.r2StorageAdaptiveGroups.0');

        if (! is_array($group)) {
            throw new UsageUnreadable(
                'Cloudflare reported no R2 storage metrics for this account. A bucket created in the '
                .'last few minutes has not been aggregated yet — try again shortly.'
            );
        }

        return [
            // Cloudflare bills payload and metadata separately; the allowance
            // covers both, so the guard must count both.
            'used' => (int) data_get($group, 'max.payloadSize', 0) + (int) data_get($group, 'max.metadataSize', 0),
            'objects' => (int) data_get($group, 'max.objectCount', 0),
            'date' => data_get($group, 'dimensions.date'),
        ];
    }

    /**
     * Drop the cached reading, so the next check asks Cloudflare again.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
