<?php

namespace App\Services\Tds;

/**
 * Phase 10B — a 26Q export pre-flight failure, carrying the row-named reasons the return
 * cannot be filed as-is (missing deductor identity, unmapped section, invalid BSR/PAN,
 * a challan that doesn't cover whole deductions, …). The point is that the user fixes it
 * inside ZeroBook, never by deciphering a generic FVU rejection.
 */
class Form26qException extends \RuntimeException
{
    public function __construct(public array $errors)
    {
        parent::__construct('26Q export failed pre-flight validation: '.implode(' | ', $errors));
    }
}
