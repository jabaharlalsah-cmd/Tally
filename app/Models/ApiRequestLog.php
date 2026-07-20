<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 16A — one row per API request that reached an authenticated key. Tenant database.
 *
 * Append-only, mirroring the central audit logs (platform_admin_actions,
 * tenant_lifecycle_events): created_at only, no updated_at.
 *
 * It stores metadata and a SHA-256 of the request body — never the body itself, and never the
 * raw key. Bodies are PII and grow without bound; the digest still answers the only question
 * support actually asks of them ("did the same payload arrive twice?").
 *
 * Requests that fail authentication are absent by construction: with no valid key there is no
 * tenant, and therefore no tenant database to write the row to.
 */
class ApiRequestLog extends Model
{
    protected $table = 'api_request_log';

    /** Append-only log — created_at is the only meaningful timestamp. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'api_key_id', 'request_id', 'method', 'path', 'query_string',
        'request_body_hash', 'response_status', 'duration_ms', 'ip', 'user_agent',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function apiKey()
    {
        return $this->belongsTo(ApiKey::class);
    }

    /** 4xx/5xx — the "error rate" denominator on the activity screen. */
    public function isError(): bool
    {
        return $this->response_status >= 400;
    }
}
