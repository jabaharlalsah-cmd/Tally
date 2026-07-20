<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 16A — the CENTRAL prefix → tenant router (see the migration for the full rationale).
 *
 * Pinned to the central connection because it is read BEFORE any tenant connection exists —
 * it is the table that decides which tenant database to open. It holds no secret: the prefix
 * is plaintext by design, and the hashed key never leaves the tenant DB.
 *
 * `revoked_at` here is a housekeeping mirror only and is deliberately not consulted when
 * authenticating; the tenant-DB row is the sole authority on revocation.
 */
class ApiKeyDirectory extends Model
{
    use UsesCentralConnection;

    protected $table = 'api_key_directory';

    /** Append-only router row — created_at is the only meaningful timestamp. */
    public const UPDATED_AT = null;

    protected $fillable = ['prefix', 'tenant_id', 'revoked_at'];

    protected $casts = [
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
