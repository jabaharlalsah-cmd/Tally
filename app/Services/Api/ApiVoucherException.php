<?php

namespace App\Services\Api;

/**
 * Phase 16B — a business refusal from the API voucher layer that carries a STABLE error code and
 * HTTP status, so the controller (or the global renderer) emits the right envelope instead of a
 * generic 500.
 *
 * Used for refusals that are not field-validation (which throws ValidationException → 422) and
 * not framework HTTP exceptions — e.g. an unsupported voucher type, an expected_total mismatch,
 * or a missing TDS Payable ledger.
 */
class ApiVoucherException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($errorCode);
    }
}
