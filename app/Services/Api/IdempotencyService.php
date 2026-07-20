<?php

namespace App\Services\Api;

use App\Models\ApiIdempotencyKey;
use App\Support\ActiveCompany;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Phase 16B — the idempotency arbiter for API writes.
 *
 * Guarantee: for one (api_key_id, idempotency_key) and one request body, the wrapped operation
 * runs AT MOST ONCE, and every retry gets the same response. Two concurrent identical requests
 * produce exactly one voucher.
 *
 * INSERT-FIRST, not check-then-insert. Checking then inserting has a race window: two requests
 * both read "no row", both post. Instead we INSERT a `pending` row first and let the unique
 * index (api_key_id, idempotency_key) reject the loser. The insert is its OWN statement, run
 * BEFORE VoucherScreen::post() — critical, because post() ALSO throws QueryException 1062 for
 * voucher-number collisions (and retries them internally). Doing the idempotency insert
 * separately means a 1062 caught here is unambiguously the idempotency-key collision, never a
 * numbering one.
 *
 *   insert pending row
 *     ├─ 1062 (someone else holds it)
 *     │    ├─ their body hash ≠ ours  → reused (409)
 *     │    ├─ their row still pending  → inflight (409 + Retry-After)
 *     │    └─ their row completed      → replay their stored response (X-Idempotency-Replay)
 *     └─ inserted (we own it)
 *          ├─ run operation
 *          │    ├─ success → UPDATE row with the response, return it
 *          │    └─ throw   → DELETE the row (so a corrected retry can proceed), rethrow
 */
class IdempotencyService
{
    /** 48 hours — long enough for any reasonable client retry, short enough to prune. */
    public const TTL_HOURS = 48;

    /**
     * The request fingerprint an idempotency row stores and compares against.
     *
     * It folds the ACTIVE COMPANY into the hash, not just the body — because the company is chosen
     * by the X-Company-Id HEADER, not the body. Without it, the same key + byte-identical body sent
     * to company A and then company B would hash the same and REPLAY company A's voucher into the
     * company-B request (returning A's voucher and creating nothing in B). Folding the company in
     * makes that a safe 409 idempotency_key_reused instead: a client must use a distinct key per
     * company, and can never receive another company's resource by reusing a key.
     */
    public static function fingerprint(Request $request): string
    {
        return hash('sha256', (ActiveCompany::id() ?? 0).':'.$request->getContent());
    }

    /**
     * Run $operation under idempotency protection.
     *
     * @param  callable():IdempotentOperation  $operation  does the real work and returns the
     *         response to store/return. It is NOT called on a replay/conflict/in-flight path.
     */
    public function run(int $apiKeyId, string $idempotencyKey, string $bodyHash, callable $operation): IdempotencyOutcome
    {
        $row = $this->claim($apiKeyId, $idempotencyKey, $bodyHash);

        if ($row === null) {
            // We did not win the row — someone else holds this key. Resolve against their row.
            return $this->resolveExisting($apiKeyId, $idempotencyKey, $bodyHash);
        }

        // We own the pending row. Do the work.
        try {
            /** @var IdempotentOperation $op */
            $op = $operation();
        } catch (Throwable $e) {
            // The operation failed — release the row so a fixed retry can succeed. A partial
            // voucher post already rolled back inside post()'s own transaction; deleting the
            // idempotency row here just removes the lock.
            $row->delete();

            throw $e;
        }

        $row->forceFill([
            'response_status' => $op->status,
            // storedBody() — NOT body(). They differ only for a show-once secret, which must never
            // be persisted here in plaintext (this table is nightly-backed-up) nor resurrected by a
            // replay. See IdempotentOperation::__construct.
            'response_body' => $op->storedBody(),
            'resource_type' => $op->resourceType,
            'resource_id' => $op->resourceId,
        ])->save();

        return IdempotencyOutcome::fresh($op->status, $op->body);
    }

    /**
     * Try to INSERT a pending row. Returns the row on success, or null if the unique index
     * rejected it (the key is already claimed). This INSERT is deliberately a standalone
     * statement so its 1062 is distinguishable from post()'s numbering 1062.
     */
    private function claim(int $apiKeyId, string $idempotencyKey, string $bodyHash): ?ApiIdempotencyKey
    {
        try {
            return ApiIdempotencyKey::create([
                'api_key_id' => $apiKeyId,
                'idempotency_key' => $idempotencyKey,
                'request_body_hash' => $bodyHash,
                'response_status' => null,   // pending
                'created_at' => now(),
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                return null;
            }

            throw $e;
        }
    }

    /** Decide what a losing/retrying request gets, based on the existing row. */
    private function resolveExisting(int $apiKeyId, string $idempotencyKey, string $bodyHash): IdempotencyOutcome
    {
        $existing = ApiIdempotencyKey::where('api_key_id', $apiKeyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing === null) {
            // Extremely narrow race: the holder deleted the row (its operation failed) between
            // our failed insert and this read. Treat as in-flight — the client retries and will
            // likely win the row next time.
            return IdempotencyOutcome::inflight();
        }

        if ($existing->request_body_hash !== $bodyHash) {
            return IdempotencyOutcome::reused();
        }

        if ($existing->isPending()) {
            return IdempotencyOutcome::inflight();
        }

        return IdempotencyOutcome::replay((int) $existing->response_status, (array) $existing->response_body);
    }

    /** MySQL errno 1062, mirroring VoucherScreen::isDuplicateKey. */
    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains(strtolower($e->getMessage()), 'duplicate');
    }
}
