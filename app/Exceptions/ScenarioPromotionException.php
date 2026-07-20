<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a scenario cannot be promoted into the real books. Promotion re-runs the
 * standard post-time validation for every provisional voucher; if any voucher fails (a
 * ledger was deleted, the books no longer balance, a TDS section expired), the whole
 * promotion transaction rolls back and this carries the per-voucher reasons back to the UI.
 */
class ScenarioPromotionException extends RuntimeException
{
    /** @param array<int|string,string> $reasons  voucher id (or '*') => human-readable reason */
    public function __construct(public array $reasons)
    {
        parent::__construct('Scenario promotion failed: '.implode('; ', $reasons));
    }
}
