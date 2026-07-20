<?php

namespace App\Services\TallyImport;

/**
 * Import-time "bulk mode" flag (Phase 7A).
 *
 * A one-time Tally migration is a SINGLE process inside a SINGLE transaction:
 * there are no concurrent posters to race, so the concurrency guards that keep
 * the interactive voucher screen correct are pure overhead here. When this flag
 * is on, the shared posting path may skip ONLY those guards:
 *
 *   • StockService — the per-item {@see StockService::lockItems()} `lockForUpdate`
 *     and the FOR-UPDATE "current read" in the weighted-average fold. Inside one
 *     transaction a plain read already sees every row THIS transaction inserted,
 *     so the average is computed from all prior imported movements correctly; the
 *     lock only ever protected against OTHER committers, of which there are none.
 *   • VoucherScreen — the {@see Voucher::nextNumber()} SELECT-MAX + duplicate-key
 *     retry loop. The importer assigns numbers from an in-memory cursor instead,
 *     so there is nothing to race and nothing to retry.
 *
 * It NEVER changes correctness: the balance gate, the GST/VAT authority, the
 * bill-wise sum check and the cost-centre allocation check all run exactly as on
 * the interactive path. Imported data that violates an invariant is rejected, not
 * massaged.
 *
 * LIFECYCLE — the flag exists ONLY for the duration of one {@see run()} call and
 * is restored (to its previous value, normally `false`) in a `finally`, even if
 * the import throws. There is deliberately no public setter: the only way to turn
 * it on is to wrap a closure, which guarantees it is turned back off. A future
 * maintainer therefore cannot accidentally leave it enabled.
 */
final class BulkMode
{
    private static bool $active = false;

    /** Whether bulk mode is currently active (consulted by StockService / VoucherScreen). */
    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Run $callback with bulk mode ON, then restore the prior state — always, even
     * on exception. Returns whatever $callback returns.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public static function run(callable $callback)
    {
        $previous = self::$active;
        self::$active = true;

        try {
            return $callback();
        } finally {
            self::$active = $previous; // cannot leak past the import transaction
        }
    }
}
