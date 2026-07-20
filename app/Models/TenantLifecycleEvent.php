<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 14C — an offboarding-lifecycle audit event (CENTRAL DB), append-only.
 */
class TenantLifecycleEvent extends Model
{
    use UsesCentralConnection;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'event', 'triggered_by_user_id', 'triggered_by_admin_id', 'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function admin()
    {
        return $this->belongsTo(PlatformAdmin::class, 'triggered_by_admin_id');
    }
}
