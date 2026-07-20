<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 16C — one queued/attempted delivery of one event to one subscription. Tenant database.
 *
 * The payload is SNAPSHOTTED at emission (payload_json) and never re-read from the voucher at
 * send time: a delivery must send what was true when the event happened, even if the voucher is
 * altered or cancelled before the retry lands.
 *
 * `event_id` is stable across every attempt of this delivery — it is the receiver's dedup key
 * (at-least-once delivery means they may see it twice; the id is how they notice).
 */
class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    /** Append-then-update; created_at is stamped explicitly, there is no updated_at column. */
    public const UPDATED_AT = null;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERING = 'delivering';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXHAUSTED = 'exhausted';

    /**
     * Backoff after each failed attempt: 5s, 30s, 5m, 30m, 3h.
     * 5 retries after the first attempt = 6 total attempts, then exhausted.
     */
    public const BACKOFF_SECONDS = [5, 30, 300, 1800, 10800];

    public const MAX_ATTEMPTS = 6;

    protected $fillable = [
        'webhook_subscription_id', 'event_id', 'event_type', 'company_id', 'payload_json',
        'attempt_count', 'status', 'next_attempt_at', 'claimed_at', 'last_attempted_at',
        'last_response_status', 'last_response_body_excerpt', 'succeeded_at', 'created_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'company_id' => 'integer',
        'attempt_count' => 'integer',
        'last_response_status' => 'integer',
        'next_attempt_at' => 'datetime',
        'claimed_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'succeeded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    /** Seconds to wait before the next attempt, given how many have already failed. */
    public static function backoffFor(int $attemptCount): ?int
    {
        // attempt_count is the number of attempts ALREADY made; index 0 = the wait after the 1st.
        return self::BACKOFF_SECONDS[$attemptCount - 1] ?? null;
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_EXHAUSTED], true);
    }
}
