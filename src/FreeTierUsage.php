<?php

namespace Riasath\R2Backup;

/**
 * A point-in-time reading of how much of the R2 storage allowance is used.
 *
 * Sizes are bytes. The allowance is whatever config('r2-backup.free_tier.limit_gb')
 * says — 10 GB for Cloudflare's free tier, or a self-imposed ceiling on a paid plan.
 */
final readonly class FreeTierUsage
{
    public function __construct(
        public int $used,
        public int $limit,
        public int $objects = 0,
        public ?string $measuredOn = null,
    ) {}

    /**
     * Bytes still available, never negative.
     */
    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    /**
     * Bytes that would remain once a backup of the given size lands.
     */
    public function remainingAfter(int $incoming): int
    {
        return max(0, $this->limit - $this->used - $incoming);
    }

    /**
     * Remaining headroom as a fraction of the allowance, e.g. 0.06 for 6% left.
     *
     * A limit of zero or less would make this meaningless, so it reports "full"
     * rather than dividing by zero — a misconfigured limit must not silently
     * disable the guard.
     */
    public function remainingRatio(): float
    {
        return $this->remainingRatioAfter(0);
    }

    public function remainingRatioAfter(int $incoming): float
    {
        if ($this->limit <= 0) {
            return 0.0;
        }

        return $this->remainingAfter($incoming) / $this->limit;
    }

    /**
     * Percentage of the allowance consumed, capped at 100 for display.
     */
    public function usedPercent(): float
    {
        if ($this->limit <= 0) {
            return 100.0;
        }

        return min(100.0, round($this->used / $this->limit * 100, 1));
    }

    /**
     * One-line summary for a console or a log entry.
     */
    public function summary(): string
    {
        return sprintf(
            '%s of %s used (%s left, %s%%)',
            BackupService::humanBytes($this->used),
            BackupService::humanBytes($this->limit),
            BackupService::humanBytes($this->remaining()),
            $this->usedPercent(),
        );
    }
}
