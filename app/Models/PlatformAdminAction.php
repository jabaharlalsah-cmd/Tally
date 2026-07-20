<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 14A — the platform-admin audit log (CENTRAL database).
 *
 * One immutable row per consequential action an operator takes against a tenant.
 * Written by App\Services\Platform\PlatformActions and the impersonation flow; never
 * updated or deleted in normal operation. Pinned to the central connection so it is
 * writable from the admin surface (which runs on a central domain) regardless of any
 * active tenant.
 */
class PlatformAdminAction extends Model
{
    use UsesCentralConnection;

    /** Only created_at is meaningful for an append-only log. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id', 'action', 'tenant_id', 'target_user_id',
        'reason', 'meta', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function admin()
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
