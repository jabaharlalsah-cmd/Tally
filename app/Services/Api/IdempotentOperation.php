<?php

namespace App\Services\Api;

/**
 * Phase 16B — what a write operation hands back to IdempotencyService: the response to store and
 * replay, plus the resource it created (for the audit trail on the idempotency row).
 *
 * Phase 16C added $storeBody: the body PERSISTED for replay, when it must differ from the body
 * RETURNED now. Exactly one thing needs that — a show-once secret. See the constructor.
 */
class IdempotentOperation
{
    /**
     * @param  array       $body       what this request returns now
     * @param  array|null  $storeBody  what a REPLAY returns; defaults to $body
     *
     * $storeBody exists because of a genuine conflict between two guarantees. Idempotency says "a
     * replay returns the original response". Show-once says "this secret is displayed exactly once
     * and is never recoverable". A webhook create/rotate response carries a plaintext signing
     * secret — so storing it verbatim for replay would (a) sit it in plaintext in
     * api_idempotency_keys for 48h, in a table nightly tenant backups capture, defeating the
     * whole point of encrypting the secret column, and (b) re-show it on every replay, which is
     * "shown once" in name only.
     *
     * Show-once wins: the secret is returned to the caller that created it, and the STORED body
     * carries a redaction notice instead. A replay is still idempotent in the way that matters —
     * no second webhook is created, the same resource comes back — it simply cannot resurrect a
     * secret the customer was told to save.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly ?string $resourceType = null,
        public readonly ?int $resourceId = null,
        public readonly ?array $storeBody = null,
    ) {}

    /** The body to persist for replay — the redacted one when the caller supplied it. */
    public function storedBody(): array
    {
        return $this->storeBody ?? $this->body;
    }
}
