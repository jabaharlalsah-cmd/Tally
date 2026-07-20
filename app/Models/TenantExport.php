<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 14C — a customer data-export archive (CENTRAL DB).
 *
 * A complete, human-readable zip of the tenant's books (CSVs + reports + mysqldump),
 * generated asynchronously. The download is a signed URL valid until download_expires_at;
 * the file lives in private storage.
 */
class TenantExport extends Model
{
    use UsesCentralConnection;

    protected $fillable = [
        'tenant_id', 'initiated_by_user_id', 'initiated_by_admin_id', 'status',
        'file_path', 'file_size_bytes', 'error',
        'started_at', 'completed_at', 'download_expires_at', 'downloaded_at',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'download_expires_at' => 'datetime',
        'downloaded_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isDownloadable(): bool
    {
        return $this->isCompleted()
            && $this->download_expires_at !== null
            && $this->download_expires_at->isFuture();
    }

    public function humanSize(): string
    {
        $b = (int) $this->file_size_bytes;

        return $b >= 1048576 ? round($b / 1048576, 2).' MB' : round($b / 1024, 1).' KB';
    }
}
