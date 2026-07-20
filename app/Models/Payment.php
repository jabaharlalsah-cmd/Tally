<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 14B (Manual) — a manual subscription payment (CENTRAL database).
 *
 * Lifecycle:
 *   pending    → customer claimed a payment (notified_by_user_id set); awaiting admin.
 *   confirmed  → admin verified it; extends the tenant's plan_ends_at.
 *   rejected   → admin declined it (rejection_reason set); no subscription change.
 *   reversed   → a confirmed payment later corrected (reversal_reason set); plan_ends_at
 *                is recomputed from the remaining confirmed payments.
 *
 * The proof snapshot + generated invoice live in PRIVATE storage and are served only
 * through access-controlled controllers — never publicly linked.
 */
class Payment extends Model
{
    use UsesCentralConnection;

    public const MODES = ['bank_transfer', 'upi', 'cheque', 'cash', 'other'];

    protected $fillable = [
        'tenant_id', 'recorded_by_admin_id', 'notified_by_user_id', 'plan_id',
        'amount', 'currency', 'payment_mode', 'reference_number', 'received_at',
        'proof_file_path', 'notes', 'status', 'invoice_number', 'invoice_file_path',
        'reversed_by_admin_id', 'reversed_at', 'reversal_reason', 'rejection_reason',
        'subscription_period_start', 'subscription_period_end',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'date',
        'reversed_at' => 'datetime',
        'subscription_period_start' => 'date',
        'subscription_period_end' => 'date',
    ];

    // ── relations ──────────────────────────────────────────────────────────────

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function recordedByAdmin()
    {
        return $this->belongsTo(PlatformAdmin::class, 'recorded_by_admin_id');
    }

    public function reversedByAdmin()
    {
        return $this->belongsTo(PlatformAdmin::class, 'reversed_by_admin_id');
    }

    public function notifiedByUser()
    {
        return $this->belongsTo(TenantUser::class, 'notified_by_user_id');
    }

    // ── state ───────────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /** Only confirmed, non-reversed payments contribute to plan_ends_at. */
    public function scopeContributing($query)
    {
        return $query->where('status', 'confirmed');
    }

    /** The proof file's extension (for the download filename / content-type). */
    public function proofExtension(): ?string
    {
        return $this->proof_file_path ? strtolower(pathinfo($this->proof_file_path, PATHINFO_EXTENSION)) : null;
    }

    public function currencySymbol(): string
    {
        return $this->currency === 'NPR' ? 'रू' : '₹';
    }

    /** "₹1,499.00" */
    public function formattedAmount(): string
    {
        return $this->currencySymbol().number_format((float) $this->amount, 2);
    }
}
