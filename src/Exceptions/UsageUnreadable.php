<?php

namespace Riasath\R2Backup\Exceptions;

use RuntimeException;

/**
 * Cloudflare could not tell us how much storage is in use right now.
 *
 * Deliberately distinct from BackupFailed: this is the transient case — a 5xx,
 * a timeout, a bucket too new to have been aggregated — which
 * config('r2-backup.free_tier.fail_open') is allowed to wave through.
 *
 * A wrong token or a malformed account ID is NOT this. Those raise BackupFailed
 * and always stop the run, because letting a misconfigured guard fail open
 * would leave an operator believing they are protected when they are not.
 */
class UsageUnreadable extends RuntimeException {}
