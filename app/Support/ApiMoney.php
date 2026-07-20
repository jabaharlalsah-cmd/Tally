<?php

namespace App\Support;

/**
 * Phase 16B — the money boundary for the API.
 *
 * Money crosses the wire as a DECIMAL STRING ("1500.00"), never a JSON number. Numbers are
 * accepted (some clients cannot avoid them) but documented as risky: a large or high-precision
 * value silently loses bits in IEEE-754 before the server ever sees it. A string does not.
 *
 * Internally ZeroBook is integer paise everywhere (the balance gate accumulates
 * `(int) round(amount*100)`; BalanceService reports paise). So the ONE job of this class is an
 * EXACT decimal-string → integer-paise parse that never touches float arithmetic — the rule the
 * brief calls non-negotiable. `1500.00` → 150000 by splitting on '.', not by `* 100`.
 *
 * The value handed to VoucherScreen::post() for a line amount is `paise / 100` — the exact same
 * form the UI's own payload builder uses (`$totalP / 100`), so an API-posted voucher and a
 * UI-posted voucher feed the shared path byte-identical inputs and produce byte-identical rows.
 */
class ApiMoney
{
    /** Rupees with at most 2 decimal places, optional leading minus. No thousands separators. */
    private const DECIMAL = '/^-?\d+(\.\d{1,2})?$/';

    /**
     * A hard ceiling so a parsed value always fits a 64-bit paise integer with room to sum an
     * invoice. 10^13 rupees = 10^15 paise, well under PHP_INT_MAX (~9.2·10^18).
     */
    public const MAX_RUPEES = 10_000_000_000_000;

    /**
     * Exact decimal-string → integer paise. No float, ever.
     *
     * Accepts a string ("1500.00", "1500.5", "1500") or an int; a float is accepted only after
     * being rendered to a fixed 2-dp string first (see normalizeInput), so no float reaches the
     * parse. Throws InvalidArgumentException on a malformed or oversized value — the controller
     * turns that into a 422.
     */
    public static function toPaise(mixed $value): int
    {
        // A non-scalar (a JSON array/object smuggled in where a money string was expected) is a
        // malformed amount, not a server fault — reject it as an InvalidArgumentException the
        // caller turns into a 422, never let it become a TypeError → 500.
        if (! is_scalar($value)) {
            throw new \InvalidArgumentException('Money amount must be a decimal string, not a '.gettype($value).'.');
        }

        $s = self::normalizeInput($value);

        if (! preg_match(self::DECIMAL, $s)) {
            throw new \InvalidArgumentException("Not a valid money amount: {$s}");
        }

        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-');

        [$whole, $frac] = str_contains($s, '.') ? explode('.', $s, 2) : [$s, ''];
        $frac = str_pad(substr($frac, 0, 2), 2, '0');   // '5' → '50', '' → '00'

        if ((int) $whole > self::MAX_RUPEES) {
            throw new \InvalidArgumentException("Money amount out of range: {$s}");
        }

        $paise = (int) $whole * 100 + (int) $frac;

        return $neg ? -$paise : $paise;
    }

    /** True when a value is a well-formed, in-range money amount. */
    public static function isValid(mixed $value): bool
    {
        try {
            self::toPaise($value);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** Integer paise → the canonical decimal string for a response. 150000 → "1500.00". */
    public static function fromPaise(int $paise): string
    {
        $neg = $paise < 0;
        $paise = abs($paise);
        $s = intdiv($paise, 100).'.'.str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);

        return $neg ? '-'.$s : $s;
    }

    /**
     * The value to hand VoucherScreen::post() for a line/allocation amount: `paise / 100`, the
     * exact form the UI payload builder uses. Kept as a float only for the shared path's own
     * `(int) round(x*100)` round-trip, which is exact for any paise value in range.
     */
    public static function forPost(int $paise): float
    {
        return $paise / 100;
    }

    /** Render a float/int/string to a fixed decimal string WITHOUT introducing float error. */
    private static function normalizeInput(string|int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // The only place a float is tolerated: pin it to 2 dp immediately so no further float
            // math happens. A client that sends a JSON number accepts this rounding (documented).
            return number_format($value, 2, '.', '');
        }

        return trim($value);
    }
}
