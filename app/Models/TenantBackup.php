<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 14C — a stored, VERIFIED backup of a tenant database (CENTRAL DB).
 *
 * The `.sql.gz` lives in private storage; `meta` records the voucher/entry counts at
 * backup time so a restore can verify it recovered the same data. `expires_at` is the
 * longest retention the backup qualifies for (daily / weekly-Sunday / monthly-1st) — the
 * nightly prune deletes rows (and files) once past it.
 */
class TenantBackup extends Model
{
    use UsesCentralConnection;

    public const TYPES = ['scheduled', 'on_demand', 'pre_offboarding'];

    protected $fillable = [
        'tenant_id', 'type', 'file_path', 'file_size_bytes', 'meta',
        'taken_at', 'verified_at', 'expires_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'file_size_bytes' => 'integer',
        'taken_at' => 'datetime',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function humanSize(): string
    {
        $b = (int) $this->file_size_bytes;

        return $b >= 1048576 ? round($b / 1048576, 2).' MB' : round($b / 1024, 1).' KB';
    }
}
