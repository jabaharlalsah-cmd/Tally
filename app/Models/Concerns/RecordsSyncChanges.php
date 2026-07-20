<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Phase 7C — appends a row to the `sync_changes` pull change-log whenever the model
 * is written or deleted, so a desktop can pull exactly the changes it hasn't seen.
 *
 * Fires for EVERY source (web SaaS, desktop sync, the Tally importer) because they
 * all go through Eloquent. It is deliberately defensive: recording is skipped
 * silently if the `sync_changes` table doesn't exist (e.g. a tenant DB not yet
 * migrated, or the central context), so it can never break a voucher post. The
 * change-log is an auxiliary index, never load-bearing for correctness.
 */
trait RecordsSyncChanges
{
    /**
     * Positive-only memo keyed by DATABASE — one process can touch several tenant
     * DBs (tenants:run, provisioning loops), and a per-process boolean would carry
     * one tenant's schema verdict into another's (mirrors BelongsToCompany's memo
     * discipline). A negative result is always re-checked, so a table that appears
     * mid-process (fresh provisioning) is observed.
     */
    private static array $syncChangesTableSeen = [];

    public static function bootRecordsSyncChanges(): void
    {
        static::saved(function ($model) {
            $model->recordSyncChange($model->wasRecentlyCreated ? 'created' : 'updated');
        });

        static::deleted(function ($model) {
            $model->recordSyncChange('deleted');
        });
    }

    private function recordSyncChange(string $op): void
    {
        try {
            $db = $this->getConnection()->getDatabaseName();
            if (empty(self::$syncChangesTableSeen[$db])) {
                if (! Schema::connection($this->getConnectionName())->hasTable('sync_changes')) {
                    return;
                }
                self::$syncChangesTableSeen[$db] = true;
            }

            $row = [
                'entity' => $this->syncEntityName(),
                'record_id' => $this->getKey(),
                'op' => $op,
                'occurred_at' => now(),
            ];

            // Phase 12A — stamp the ROW's company, read from the model itself (never
            // the session: this fires from imports and artisan too, and on delete the
            // active company may differ from the row's). Omitted when the model has
            // no company_id yet (a pre-12A tenant mid-deploy), keeping the insert valid.
            if ($this->getAttribute('company_id') !== null) {
                $row['company_id'] = $this->getAttribute('company_id');
            }

            $this->getConnection()->table('sync_changes')->insert($row);
        } catch (Throwable $e) {
            // A change-log failure must never break the write it is logging.
        }
    }

    /** The entity label recorded in the change-log (override per model if needed). */
    protected function syncEntityName(): string
    {
        return strtolower(class_basename($this));
    }
}
