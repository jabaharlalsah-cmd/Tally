<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 16B — an idempotency record for one API write. Tenant database.
 *
 * A row is created `pending` (response_status NULL) BEFORE the write runs; on success it is
 * updated with the exact response to replay, on failure it is deleted so a corrected retry can
 * proceed. See the migration for the full insert-first rationale.
 *
 * NOT append-only (unlike the 16A audit tables): a pending row is updated to completed, so it
 * keeps updated_at semantics off but is mutated once. We only ever set created_at explicitly.
 */
class ApiIdempotencyKey extends Model
{
    protected $table = 'api_idempotency_keys';

    /** created_at is stamped explicitly; there is no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'api_key_id', 'idempotency_key', 'request_body_hash',
        'response_status', 'response_body',
        'resource_type', 'resource_id',
        'created_at', 'expires_at',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'response_body' => 'array',
        'resource_id' => 'integer',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** A row with no response_status yet — the operation is still running (or crashed). */
    public function isPending(): bool
    {
        return $this->response_status === null;
    }
}
